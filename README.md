# Refine the project schedule, task dates, and sidebar

## Why

Project Schedule spread its controls across multiple rows, and the task form combined the two scheduling dates in a single field. Pale category colors made some labels hard to read. Running timers and account settings also needed clearer separation.

## Changes

- Place the view selector, date navigation, and Create Task on one toolbar. Show compact schedule dates and place elapsed time beside task controls.
- Display both dates in one range input and use a shared calendar that fills both endpoints and shades the dates between them. Hover a day to preview the range, then apply the changes from the calendar footer. Advance to the due date after choosing a start date and keep the displayed month. Reject reversed dates before applying and keep the Ongoing option.
- Use the primary text color for category labels and preserve their colors as dots.
- Separate running timers from the language and profile settings. Move Log Out and Feedback to the organization menu.

## Verification

Built the frontend and ran Vue type checking. In the local preview, created and edited a sample task, checked the inline date values, stored dates, and the range fill, hover preview, and endpoint markers including same-day dates, automatic endpoint switching with keyboard focus and month preserved, draft dismissal, narrow-screen layout, the invalid-range message and open-ended Schedule bar, and confirmed the organization menu actually signs out the preview account.

## Screenshots

![Project Schedule and separated sidebar sections](.github/feature-images/refined-project-schedule-layout.png)

![Task dates with filled endpoints and a shaded range across weeks](.github/feature-images/project-task-unified-date-picker.png)

![Readable task categories and sidebar separation](.github/feature-images/project-task-contrast-and-sidebar.png)

![Log Out in the organization menu](.github/feature-images/project-task-organization-menu.png)

[Upstream Solidtime README](https://github.com/solidtime-io/solidtime#readme)
