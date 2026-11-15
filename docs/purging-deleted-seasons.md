# Deleted seasons: restore and purge

Deleting a season only soft-deletes it (`deletedAt` is set). Owners can restore it from
**Settings > Deleted seasons**, and admins from the **Deleted seasons** tab in `/admin`.

After `Season::RETENTION_DAYS` (31) days the `tvdt:purge-deleted-seasons` command removes the season
and everything it owns for good, through `SeasonRepository::deleteSeason()`. The delete modal
promises one day less (30), so the daily run can never purge before the promised period is over.
The retention period is a constant on purpose and is not configurable.

```bash
bin/console tvdt:purge-deleted-seasons --dry-run   # list what would be purged
bin/console tvdt:purge-deleted-seasons             # purge
```

## Scheduling

The Helm chart ships a `CronJob` (`purgeDeletedSeasons` in `values.yaml`, enabled by default, daily at
03:17). Set `purgeDeletedSeasons.enabled: false` to run the command from your own scheduler instead.
