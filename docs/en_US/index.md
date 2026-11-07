# Usual presence

Presence simulation **drawn from your history**. The plugin records when your lights are switched on and your shutters opened, draws the trends (earliest, latest, on average), the exceptions and what never happens, then (in the next versions) replays them while you are away, with adjustable variations.

## Privacy

Everything is computed on the box. The plugin makes **no network call** (checked by `tests/selftest.php`), uses no online service and no "AI". State changes are kept in a table of the Jeedom database (`presencehabituelle_event`) and computed trends in `data/habitudes.json`, the plan in `data/plan.json` and its images in `data/plan_*.jpg` (not reachable from the Web). Only administrators can see them. Like the rest of the database, they are part of the **Jeedom backups**, including a cloud backup if you set one up. The **Erase all** button (Trends tab) deletes the records and the trends (the plan is erased by deleting its floors); they are also erased when the plugin is reinstalled after being removed. **Before uninstalling, click Erase all**: uninstalling alone leaves the table in the database.

## Guide (four questions)

The plugin knows nothing about your house. The **Guide** tab reads your devices and their history, then asks you four questions; your answers fill in the "Followed commands" and prepare the future simulation.

1. **Is it a lamp or a shutter of the house?** For each light, shutter, plug (one per device) or command flagged by the discovery: **Yes, follow it**, **No** (a fridge plug, a status LED), **Duplicate of…** (the same lamp seen by another command: the chosen one is kept) or **Group of lamps** (a command that switches several lamps already listed). **Identify by switching on** shows what changes while you switch a lamp on or off: if several commands change together (within 5 seconds), keep the one that stands for it, the others become its duplicates. Any binary or typed command that changes shows up, even if it was not offered (a virtual, the second command of a device). A command cannot be the duplicate of a duplicate; if the kept command gets another answer, its duplicates follow it (duplicate) or are "to review".
2. **Where are they?** (optional) The followed commands not yet placed: a click opens the Plan tab to place them.
3. **Any rules?** (optional) Per lamp or shutter: **Never simulate it** (not even while you are away), or time ranges: "may switch on" (only within these ranges) or "never on" (never within these), every day, on weekdays or at the weekend (as set in the configuration). From 23:00 to 07:00 crosses midnight; from 00:00 to 00:00 = the whole day.
4. **Not enough data yet**: the followed commands recorded for less than 10 days (a non-historized command only from the time it is saved). Wait, or give them a "may switch on" range.

The **summary** says, for each followed command: habits (10 days of records or more), ranges given, never simulated, or to complete. When nothing is left to complete: "Simulation can be proposed" — nothing is started for now. **Save** keeps the answers, the rules, the choice of commands and the plan together (a range without a time is refused); **Back to the previous choice** brings them back too.

## For now: analysis only

The plugin **acts on nothing yet**: it analyses your history and shows what it draws from it.

1. **Followed commands**: state commands are offered according to their generic type.
   - Lights (`LIGHT_STATE`) and shutters (`FLAP_STATE`): ticked.
   - Plugs and media: offered unticked (a plug may power a fridge).
   - Sensors and others: hidden, not recommended.
   - A command without a generic type is not offered by default: set its type, or tick "Show other commands".
2. **Groups**: each lamp is analysed alone; give several lamps the same group to analyse them together (a room), or use "Group by Jeedom object".
3. **History**: when saving, the state changes of the last 90 days are imported from the Jeedom history (if the command is historized). A command that is not historized is recorded from the time it is saved. After a few hours (2 h by default, "archiving" setting), Jeedom replaces numeric values with an hourly average: when the lamp went on is lost. For such a command (flagged "smoothed history"), only the last hours are imported; set its smoothing to "None" (command configuration, History tab) so that the whole history serves in future. A binary command stays exact. The state at the start of the period (lamp already on, shutter already closed) is imported too.
   A command added later is imported the same way. Only one state command per device and per type is offered ticked.
4. **Absences**: a binary or numeric command that is true while you are away (a text "Away" mode will not do: use a binary virtual) and periods entered by hand. Days touched are not counted, otherwise the plugin would draw the trends of the empty house — or an old simulation. Its history is imported over the whole period (90 days by default); a smoothed numeric command is refused (set its smoothing to "None" or use a binary virtual). When the absence command is changed, the absences of the previous one are kept. Days when the plugin was disabled, or the box stopped for over 3 hours, are not counted either (nothing was recorded).
5. **Discover**: the plugin reads the last 30 days of history and makes proposals, ticked or not, which you confirm. It knows nothing about your house: it relies on generic types, names and what the history shows.
   - **Duplicates**: two commands that switch together (within 5 seconds) at least 9 times out of 10 are one lamp seen twice (for example by the device's plugin and by a gateway's), or two lamps always switched together. The historized one is kept; the other is unticked, marked "duplicate of …".
   - **Groups**: a command that is on only when others are, and switches with them (a Hue group, "Whole living room"), is unticked and marked "group of …": its lamps are enough.
   - **Lights without a generic type**: a binary command whose name or device suggests a lamp (lampe, applique, plafonnier, light…) is shown as a light, unticked, "generic type to fill in?".
   - **Days without any light**: possible absences, proposed unticked (a day without light can also be a summer day); tick those when the house was empty to add them to the periods not to analyse.
   - **Smoothed history**: a lamp whose history is smoothed (beyond a few hours Jeedom keeps only an hourly average) allows no comparison; set its smoothing to "None" or watch it for 3 days.
   - **Possible absence command**: an armed alarm or a command whose name speaks of absence, holidays or simulation (binary, or numeric not smoothed), proposed unticked.
   - **Automatic lights?**: a lamp switched on nearly every day at the same time (within 5 minutes) or at sunset (within 10 minutes, from the box's location) is only flagged: untick it yourself if a program switches it on.
   - **Without history**: nothing to compare. **Watch for 3 days** records their changes for 3 days (without analysing anything); then run the discovery again. Records are kept 7 days after the end of the watch; then those of the commands you did not tick are deleted.

   **Apply to the list** carries the ticked proposals over; nothing is saved before **Save**. **Back to the previous choice** reloads into the list the choice from before the last save (commands, groups, absence command, periods); save to go back to it. Before saving a choice that removes commands, the plugin asks for confirmation: the records of a removed command are deleted and do not come back with the previous choice; only the Jeedom history is imported again (usually 90 days, a few hours if smoothed).

   Periods when the plugin itself simulates presence will be excluded automatically, like its stops: it will not draw its trends from its own simulation.

The **Trends** tab shows, for each lamp or group:
- the percentage of days it is on during each hour, on weekdays and at the weekend;
- **what never happens**: the hours it has never been on;
- **exceptions**: days it was on at an hour when it is on at most 10 % of days;
- how long it stays on: average, median, half of the switch-ons between two values, shortest and longest, the share of passes shorter than 3 minutes, and unusual durations;
- lamps switched on together.

A row (weekdays or weekend) taken from fewer than 10 days is marked "to be confirmed".

A switch-on longer than 18 hours (adjustable) is taken as a missed switch-off (box stopped, bridge offline): it is discarded, and the days it touches do not count for its group (or the lamp alone).

For each shutter, on weekdays and at the weekend: morning opening and evening closing time (median, average, earliest, latest), the share of nights it is closed ("never" or "always" when so) and exceptions. An exception is a day far from the middle half of the values: by more than 30 minutes and by more than one and a half times the spread of that half (whichever is larger, Tukey's rule); at least 4 values are needed. A time taken from fewer than 10 values, or a share of nights taken from fewer than 10 nights, is marked "to be confirmed", separately for weekdays and the weekend. A morning opening is only noted if the shutter was closed at 04:00: reopening it after closing it against the heat is not getting up. A night (16:00 → 04:00) touched by an absence is not counted.

The day runs from 04:00 to 04:00, for lights as for shutters. By default the weekend runs from Friday 16:00 to Monday 04:00: Friday to Sunday evenings (16:00-04:00) and Saturday and Sunday mornings (04:00-16:00); an hour between 00:00 and 04:00 counts for the previous evening. It is set in the plugin configuration (**Weekend starts** / **ends**, day and time), for the analysis as for the rules; then click **Recompute**.

It is recomputed every night; changes older than 90 days are deleted (adjustable in the plugin configuration), to follow the season.

## House plan (optional)

The **Plan** tab draws the house, one plan per floor:

1. **＋ Floor**: name it ("Ground floor", "First floor"…); the arrows change the order. **Image…** uploads the floor plan: a photo of a paper plan, a screenshot, a software export (PNG, JPEG…). It is reduced to 2,000 pixels and kept on the box; without an image, you draw on a grid.
2. **Place**: click a light or a shutter under "Not on the plan", then the spot on the plan. Drag a marker to move it; the **Delete** tool sends it back to the list. If you already have a Jeedom design with your lights placed, **Take the positions from a design** reuses them (centre of each widget, in proportion to the design: use the design's background as the floor image).
3. **Room**: click the corners, then "Finish the shape", and name it. The lamps of a room are analysed together, like a group; a group typed under "Followed commands" takes precedence. Two rooms with the same name form a single group, separate from a typed group of the same name.
4. **Passage**: click both ends (a door, a corridor, the top of a staircase), then choose the two linked rooms and the type; for a staircase, the second room may be on another floor. The plugin counts the **sequences**: the times a light of the second room turns on less than 3 minutes after a light of the first one turns off (and the other way round). They will be used for the simulation route.
5. **Zone to exclude**: a part of the house you want neither analysed nor simulated (a guest room, a rented flat): the commands placed inside are ignored.

**Save the plan** keeps everything and recomputes the trends. Each marker shows the trend at the chosen hour ("Trend at", on weekdays or at the weekend): the fuller it is, the more often the lamp is on at that hour; for a shutter, full if it is usually open. Hover over it for details.

## Light sensor

The plugin will work without a light sensor, from the height of the sun. An outdoor sensor is however **a real plus**: on grey days, lights go on earlier and in the middle of the day, which the sun's height cannot see. (Planned in a future version.)
