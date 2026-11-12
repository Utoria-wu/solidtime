# Show a weekly schedule for project tasks

Project task lists show due dates, but it takes several rows to see what can be started now and how planned dates overlap. Open **Projects → a project → Active → Schedule** to see active tasks alongside a weekly timeline.

- Tasks are grouped by whether they are overdue, ready to work on, upcoming, or unscheduled.
- Gradient bars show planned ranges, with a tick on the deadline and a diamond for single-day deadlines.
- The timeline highlights today. Use the week arrows to browse and **Today** to return to the current week.
- The view reuses the existing task details, row actions, and timers. **List** opens by default.

![Weekly Schedule in Solidtime with sample tasks](.github/feature-images/project-task-schedule.png)

## Verification

Built the frontend and ran type checking and lint. Used Edge with the built assets and a local Solidtime API to check all four groups, gradient deadline alignment, week navigation, task details, the Done list, and starting and pausing a real test timer across the two views.

## Schedule across projects

Open **Projects → Schedule** to see tasks grouped by project on one weekly timeline. Project search and filters also apply in this view. Completed tasks are labeled **Done**, and tasks without dates remain listed under their project. The current day is highlighted in the date header. To open a single project directly in Schedule, use **Open Schedule** in its actions menu.

![Projects overview showing multiple projects on the same weekly schedule](.github/feature-images/projects-overview-schedule.png)

Edge verification with the local API covered two projects on the same timeline, completed tasks, search filtering, the highlighted date, and switching between the overview, individual schedule, and List views.

[Upstream Solidtime README](https://github.com/solidtime-io/solidtime#readme)
