# Sort projects and tasks by creation and completion dates

When a workspace has many similarly named projects and tasks, names alone make it hard to find recent work. The Projects list now shows a sortable **Created** column. The task list adds sortable **Created** and **Completed** columns next to the existing planned due date. Clicking a date heading first sorts newest first.

Existing records use their stored timestamps. Reopening a task clears the completion timestamp, and marking an already completed task as done again preserves its timestamp.

![Project list sorted by creation time](.github/feature-images/projects-created-date.png)

![Completed tasks with creation and completion dates](.github/feature-images/tasks-created-completed-dates.png)

## Verification

The frontend build and TypeScript check passed. A local browser and API check verified project and task timestamps, both list views, sorting controls, repeated completion, and reopening.

[Upstream Solidtime README](https://github.com/solidtime-io/solidtime#readme)
