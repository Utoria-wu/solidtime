# Refine the project schedule, task dates, and sidebar

## Why

Project Schedule spread its controls across multiple rows, and the task form combined the two scheduling dates in a single field. Pale category colors made some labels hard to read. Running timers and account settings also needed clearer separation.

## Changes

- Place the view selector, date navigation, and Create Task on one toolbar. Show compact schedule dates and place elapsed time beside task controls.
- Give Start date and Due date their own calendar buttons. Reject reversed dates before saving and keep the Ongoing option in the due date picker.
- Use the primary text color for category labels and preserve their colors as dots.
- Separate running timers from the language and profile settings. Move Log Out and Feedback to the organization menu.

## Verification

Built the frontend and ran Vue type checking. In the local preview, created and edited a sample task, checked the stored dates, verified the invalid-range message and open-ended Schedule bar, and confirmed the organization menu actually signs out the preview account.

## Screenshots

![Project Schedule and separated sidebar sections](.github/feature-images/refined-project-schedule-layout.png)

![Create Task with independent Start date and Due date controls](.github/feature-images/project-task-separate-dates.png)

![Readable task categories and sidebar separation](.github/feature-images/project-task-contrast-and-sidebar.png)

![Log Out in the organization menu](.github/feature-images/project-task-organization-menu.png)

[Upstream Solidtime README](https://github.com/solidtime-io/solidtime#readme)
