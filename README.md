# Show a weekly schedule for project tasks

Project task lists show due dates, but it takes several rows to see what can be started now and how planned dates overlap. Open **Projects → a project → Active → Schedule** to see active tasks alongside a weekly timeline.

- Tasks are grouped by whether they are overdue, ready to work on, upcoming, or unscheduled.
- Gradient bars show planned ranges, with a tick on the deadline and a diamond for single-day deadlines.
- The timeline highlights today. Use the week arrows to browse and **Today** to return to the current week.
- The view reuses the existing task details, row actions, and timers. **List** opens by default.

![Weekly Schedule in Solidtime with sample tasks](.github/feature-images/project-task-schedule.png)

## Verification

Built the frontend and ran type checking and lint. Used Edge with the built assets and a local Solidtime API to check all four groups, gradient deadline alignment, week navigation, task details, the Done list, and starting and pausing a real test timer across the two views.

[Upstream Solidtime README](https://github.com/solidtime-io/solidtime#readme)
