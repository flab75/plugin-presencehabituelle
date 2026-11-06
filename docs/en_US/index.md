# Usual presence

Presence simulation **learnt from your habits**. The plugin observes when your lights are switched on and your shutters opened, then (in the next versions) replays these habits while you are away, with adjustable variations.

## Privacy

Everything is computed on the box. The plugin makes **no network call** (checked by `tests/selftest.php`), uses no online service and no "AI". State changes are kept in a table of the Jeedom database (`presencehabituelle_event`), dropped when the plugin is removed. Only administrators can see the habits.

## Version 0.1: learning only

Version 0.1 **acts on nothing**: it learns and shows what it learnt.

1. **Followed commands**: state commands are offered according to their generic type.
   - Lights (`LIGHT_STATE`) and shutters (`FLAP_STATE`): ticked.
   - Plugs and media: offered unticked (a plug may power a fridge).
   - Sensors and others: hidden, not recommended.
   - A command without a generic type is not offered by default: set its type, or tick "Show other commands".
2. **Groups**: each lamp is learnt alone; give several lamps the same group to learn them together (a room), or use "Group by Jeedom object".
3. **History**: when saving, the state changes of the last 90 days are imported from the Jeedom history (if the command is historized). A command that is not historized is learnt from the time it is saved. After 2 days, Jeedom keeps by default an hourly average of numeric values: hours stay right, but the length of a switch-on does not. A binary command, or one with history mode "None", stays exact.
4. **Absences**: a command that is true while you are away (an "Away" mode) and periods entered by hand. Days touched are not counted, otherwise the plugin would learn the empty house — or an old simulation.

The **Habits** tab shows, for each lamp or group:
- the percentage of days it is on during each hour, on weekdays and at the weekend;
- how long it stays on (median, and half of the switch-ons between two values) and the share of passes shorter than 3 minutes;
- lamps switched on together.

For each shutter: morning opening time, evening closing time, and the share of nights it is closed. A habit computed on fewer than 10 days is marked "to be confirmed".

It is recomputed every night; changes older than 90 days are deleted (adjustable in the plugin configuration), to follow the season.

## Light sensor

The plugin will work without a light sensor, from the height of the sun. An outdoor sensor is however **a real plus**: on grey days, lights go on earlier and in the middle of the day, which the sun's height cannot see. (Planned from 0.2.)
