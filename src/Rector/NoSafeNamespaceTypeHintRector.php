<?php

declare(strict_types=1);

namespace Tvdt\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Using Safe\ namespace classes in type hints or instanceof/is_a() checks is a mistake: callers may
 * hold plain \DateTimeImmutable (or any other non-Safe subtype) and will get a type error at runtime.
 * Safe\ classes extend their standard-library equivalents, so the parent class is always the right type.
 */
final class NoSafeNamespaceTypeHintRector extends AbstractRector
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace Safe\\ namespace type hints with their parent class to avoid rejecting compatible non-Safe objects',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        class Foo
                        {
                            public ?\Safe\DateTimeImmutable $expiresAt;

                            public function setDate(\Safe\DateTimeImmutable $date): void
                            {
                                if ($date instanceof \Safe\DateTimeImmutable) {
                                    // ...
                                }
                                is_a($date, \Safe\DateTimeImmutable::class);
                            }
                        }
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        class Foo
                        {
                            public ?\DateTimeImmutable $expiresAt;

                            public function setDate(\DateTimeImmutable $date): void
                            {
                                if ($date instanceof \DateTimeImmutable) {
                                    // ...
                                }
                                is_a($date, \DateTimeImmutable::class);
                            }
                        }
                        CODE_SAMPLE,
                ),
            ],
        );
    }

    /** @return array<class-string<Node>> */
    public function getNodeTypes(): array
    {
        return [
            Property::class,
            Param::class,
            Instanceof_::class,
            FuncCall::class,
        ];
    }

    /** @param Property|Param|Instanceof_|FuncCall $node */
    public function refactor(Node $node): ?Node
    {
        if ($node instanceof Property) {
            return $this->refactorProperty($node);
        }

        if ($node instanceof Param) {
            return $this->refactorParam($node);
        }

        if ($node instanceof Instanceof_) {
            return $this->refactorInstanceof($node);
        }

        if ($node instanceof FuncCall) {
            return $this->refactorIsA($node);
        }

        return null;
    }

    private function refactorProperty(Property $property): ?Property
    {
        if (!$property->type instanceof Node) {
            return null;
        }

        $newType = $this->replaceType($property->type);
        if (null === $newType) {
            return null;
        }

        $property->type = $newType;

        return $property;
    }

    private function refactorParam(Param $param): ?Param
    {
        if (!$param->type instanceof Node) {
            return null;
        }

        $newType = $this->replaceType($param->type);
        if (null === $newType) {
            return null;
        }

        $param->type = $newType;

        return $param;
    }

    private function refactorInstanceof(Instanceof_ $instanceof): ?Instanceof_
    {
        if (!$instanceof->class instanceof Name) {
            return null;
        }

        $replacement = $this->resolveParentName($instanceof->class);
        if (!$replacement instanceof FullyQualified) {
            return null;
        }

        $instanceof->class = $replacement;

        return $instanceof;
    }

    private function refactorIsA(FuncCall $funcCall): ?FuncCall
    {
        if (!$this->isNames($funcCall, ['is_a', 'is_subclass_of'])) {
            return null;
        }

        if (!isset($funcCall->args[1]) || !$funcCall->args[1] instanceof Arg) {
            return null;
        }

        $classArg = $funcCall->args[1]->value;

        if ($classArg instanceof ClassConstFetch
            && $classArg->class instanceof Name
            && $classArg->name instanceof Identifier
            && 'class' === $classArg->name->name
        ) {
            $replacement = $this->resolveParentName($classArg->class);
            if (!$replacement instanceof FullyQualified) {
                return null;
            }

            $classArg->class = $replacement;

            return $funcCall;
        }

        if ($classArg instanceof String_) {
            $className = mb_ltrim($classArg->value, '\\');
            if (!str_starts_with($className, 'Safe\\')) {
                return null;
            }

            $parentClass = $this->getParentClassName($className);
            if (null === $parentClass) {
                return null;
            }

            $classArg->value = '\\'.$parentClass;

            return $funcCall;
        }

        return null;
    }

    /**
     * Walks a type node and replaces any Safe\ class name with its parent class.
     * Returns the modified node when a replacement was made, null otherwise.
     */
    private function replaceType(ComplexType|Identifier|Name $type): ComplexType|Name|null
    {
        if ($type instanceof Identifier) {
            return null;
        }

        if ($type instanceof Name) {
            return $this->resolveParentName($type);
        }

        if ($type instanceof NullableType) {
            // A nullable type can only ever wrap a single Identifier|Name (PHP has no `?(A&B)` or
            // `?(A|B)` syntax), so a Safe\X class there can only resolve to another plain Name.
            $replaced = $this->replaceType($type->type);
            if (!$replaced instanceof Name) {
                return null;
            }

            $type->type = $replaced;

            return $type;
        }

        if (!$type instanceof UnionType && !$type instanceof IntersectionType) {
            return null;
        }

        // Each member can itself be Identifier|Name|IntersectionType (for UnionType) or
        // Identifier|Name (for IntersectionType) — never NullableType/UnionType.
        $changed = false;
        foreach ($type->types as $i => $innerType) {
            $replaced = $this->replaceType($innerType);
            if ($replaced instanceof Name) {
                $type->types[$i] = $replaced;
                $changed = true;
            }
        }

        return $changed ? $type : null;
    }

    /**
     * Returns a FullyQualified parent class name if the given Name is in the Safe\ namespace,
     * or null if no replacement is needed.
     */
    private function resolveParentName(Name $name): ?FullyQualified
    {
        $className = $name->toString();
        if (!str_starts_with($className, 'Safe\\')) {
            return null;
        }

        $parentClass = $this->getParentClassName($className);
        if (null === $parentClass) {
            return null;
        }

        return new FullyQualified($parentClass);
    }

    private function getParentClassName(string $safeClass): ?string
    {
        if (!$this->reflectionProvider->hasClass($safeClass)) {
            return null;
        }

        $classReflection = $this->reflectionProvider->getClass($safeClass);
        $parentReflection = $classReflection->getParentClass();

        if (!$parentReflection instanceof ClassReflection) {
            return null;
        }

        return $parentReflection->getName();
    }
}
