# Usual presence

Presence simulation **drawn from your history**. The plugin records when your lights are switched on and your shutters opened, draws the trends (earliest, latest, on average), the exceptions and what never happens, then (in the next versions) replays them while you are away, with adjustable variations.

## Privacy

Everything is computed on the box. The plugin makes **no network call** (checked by `tests/selftest.php`), uses no online service and no "AI". State changes are kept in a table of the Jeedom database (`presencehabituelle_event`) and computed trends in `data/habitudes.json` (not reachable from the Web). Only administrators can see them. Like the rest of the database, they are part of the **Jeedom backups**, including a cloud backup if you set one up. The **Erase all** button (Trends tab) deletes them; they are also erased when the plugin is reinstalled after being removed.

## For now: analysis only

The plugin **acts on nothing yet**: it analyses your history and shows what it draws from it.

1. **Followed commands**: state commands are offered according to their generic type.
   - Lights (`LIGHT_STATE`) and shutters (`FLAP_STATE`): ticked.
   - Plugs and media: offered unticked (a plug may power a fridge).
   - Sensors and others: hidden, not recommended.
   - A command without a generic type is not offered by default: set its type, or tick "Show other commands".
2. **Groups**: each lamp is analysed alone; give several lamps the same group to analyse them together (a room), or use "Group by Jeedom object".
3. **History**: when saving, the state changes of the last 90 days are imported from the Jeedom history (if the command is historized). A command that is not historized is recorded from the time it is saved. After a few hours (2 h by default, "archiving" setting), Jeedom replaces numeric values with an hourly average: when the lamp went on is lost. For such a command (flagged "smoothed history"), only the last hours are imported; set its smoothing to "None" (command configuration, History tab) so that the whole history serves in future. A binary command stays exact.
   A command added later is imported the same way. Only one state command per device and per type is offered ticked.
4. **Absences**: a command that is true while you are away (an "Away" mode) and periods entered by hand. Days touched are not counted, otherwise the plugin would draw the trends of the empty house — or an old simulation.

The **Trends** tab shows, for each lamp or group:
- the percentage of days it is on during each hour, on weekdays and at the weekend;
- **what never happens**: the hours it has never been on;
- **exceptions**: days it was on at an hour when it is on at most 10 % of days;
- how long it stays on: average, median, half of the switch-ons between two values, shortest and longest, the share of passes shorter than 3 minutes, and unusual durations;
- lamps switched on together.

A switch-on longer than 18 hours (adjustable) is taken as a missed switch-off (box stopped, bridge offline): it is discarded, and the days it touches do not count for this lamp.

For each shutter, on weekdays and at the weekend: morning opening and evening closing time (median, average, earliest, latest), the share of nights it is closed ("never" or "always" when so) and exceptions. An exception is a day far from the middle half of the values: by more than 30 minutes, or by more than one and a half times the spread of that half (Tukey's rule); at least 4 values are needed. A trend computed on fewer than 10 days is marked "to be confirmed".

It is recomputed every night; changes older than 90 days are deleted (adjustable in the plugin configuration), to follow the season.

## Light sensor

The plugin will work without a light sensor, from the height of the sun. An outdoor sensor is however **a real plus**: on grey days, lights go on earlier and in the middle of the day, which the sun's height cannot see. (Planned in a future version.)
