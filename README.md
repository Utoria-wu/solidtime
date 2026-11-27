# Batch classification in Project History

Organizing a project timeline can mean updating several related entries. The History timeline now supports selecting task events and standalone Calendar activity in a single batch.

## What changes

- Choose **Select events**, select individual rows or all visible editable rows, and open **Edit selected**.
- Set or clear **Category** and **Stage** independently. Rows show their classification, and both fields can be used to filter the timeline.
- Task events share the task's classification. A Calendar entry can store its own classification whether it is linked to a task or stands alone.
- The server saves the batch in one transaction and checks that each entry belongs to the project and can be edited by the current user.

## Screenshots

![The batch editor with one task and one standalone Calendar entry selected](.github/feature-images/history-bulk-edit.png)

![The project timeline showing the saved category and stage on both entries](.github/feature-images/history-classified-timeline.png)

## Verification

- The local browser preview used sample data and mocked API responses to exercise mixed selection, deduplication of task events, reload, the standalone Calendar filter, and keeping the editor open after a failed save.
- Vite build, TypeScript checking, and the targeted History frontend tests passed.
- The endpoint tests use a local PostgreSQL database with sample data. They check mixed updates, read-back through the Calendar API, clearing a field, and rollback for an unauthorized or moved entry.

[Upstream Solidtime README](https://github.com/solidtime-io/solidtime/blob/main/README.md)
