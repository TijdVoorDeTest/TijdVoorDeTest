{{/* Based on the API Platform 4.1 chart (helm/api-platform). */}}

{{- define "tvdt.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "tvdt.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{- define "tvdt.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "tvdt.labels" -}}
helm.sh/chart: {{ include "tvdt.chart" . }}
{{ include "tvdt.selectorLabels" . }}
app.kubernetes.io/version: {{ include "tvdt.imageTag" . | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{- define "tvdt.selectorLabels" -}}
app.kubernetes.io/name: {{ include "tvdt.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{- define "tvdt.imageTag" -}}
{{ required "image.tag is required" (.Values.image.tag | default .Chart.AppVersion) }}
{{- end }}

{{- define "tvdt.image" -}}
{{ .Values.image.repository }}:{{ include "tvdt.imageTag" . }}
{{- end }}

{{- define "tvdt.secretName" -}}
{{- .Values.secrets.existingSecret | default (include "tvdt.fullname" .) }}
{{- end }}

{{- define "tvdt.databaseEnv" -}}
- name: DATABASE_URI
  valueFrom:
    secretKeyRef:
      name: {{ required "database.existingSecret is required" .Values.database.existingSecret }}
      key: {{ .Values.database.uriKey }}
- name: DATABASE_URL
  value: "$(DATABASE_URI)?serverVersion={{ .Values.database.serverVersion }}&charset={{ .Values.database.charset }}"
{{- end }}
