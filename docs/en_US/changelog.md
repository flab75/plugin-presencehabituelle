# Changelog

## 0.14
- Documentation (2026-11-08): **Calibration** section (what is compared and corrected, tolerances, nightly check, habits changed, Calage tab), "sauf exception" Guide range, widget's Habitudes changées command.
- Recalibration ("Automatic recalibration" option, off by default): each night the simulation is replayed in memory over the last 28 counted real days and compared room by room (hours lit, switch-ons, duration, 24 h profile). Two settings per room (frequency, duration, between ÷3 and ×3) are corrected, at most 5 rounds per night, until within tolerances (±15 % hours, ±25 % switch-ons, adjustable).
- Once calibrated, no more replay: a light check compares the last 14 days with the calibration reference every night. A room out of tolerance 3 nights in a row: "Habits changed" state, Jeedom message and "Habitudes changées" info command on the widget. Calibration is restarted with a button.
- New Calibration tab: state, real / simulated table per room with gaps, settings, 24 h bands, shutters; "Restart calibration" and "Reset to original settings" buttons.
- Guide rules: a "never on" range can be "except on exception days" (about one day in 30, drawn at random, it does not apply).
- The frequency setting only acts on switching on: a session still ends by the profile. Nightly check on switch-ons only, skipped when no new day is counted; rooms changed in the Guide trigger a new calibration; stops "at best" after 20 rounds.

## 0.13.2
- Fix: the upper light threshold ("lux_haut") was no longer read since 0.13.1; with a sensor, dimness jumped from 0 to 1 at the lower threshold.

## 0.13.1
- Fixes after the adverse review of 0.13:
  - "Really send" unticked during Simulation On: the lamps really switched on are switched off, then the simulation goes on as a dry run; ticked: it starts again from scratch (the dry-run state is no longer taken for the house);
  - the state is saved before sending: a log that cannot be written (full disk) no longer makes orders sent twice or lost;
  - a room taken out of the simulation is switched off instead of staying on; a lamp without a command to switch it off is not switched on; a lost "switch off" is sent again instead of being taken for a visitor; a lamp already on when On starts counts as a visitor;
  - shutters: a shutter already closed is no longer "closed" then opened again, nor a shutter without a command to open it; opening in the morning only (a "may" range no longer opens it in the evening); a shutter taken out of the simulation that it had closed is opened in the morning;
  - 04:00 day right on clock-change days; light sensor in the `#1234#` format; night threshold of 0 lux allowed; "Rooms lit even by day" ignoring case, and for a name made of figures;
  - Simulation tab: no more invented shutters on Off; widget updated when the configuration changes; "sent" no longer shown on the Simulation On / Off lines; a quick passage lasts 5 minutes, as the documentation now says;
  - "Erase all" also erases the simulation log; simulation periods purged like the others; documentation up to date (configuration, privacy, shutters, visitor with real sending only).

## 0.13
- **Simulation** tab and **Simulation On / Off** commands of the widget: room by room, from its trends when it has enough counted days, otherwise from provisional times for its room type (kitchen, living room, bedroom…); only in the dusk (light sensor or sun), shift and activity drawn each day, scenes, Guide rules, at most 3 rooms at once, shutters opened and closed, someone switching a lamp left alone 60 min; Off switches off what it switched on; a room reaching an hour when it has never been on is switched off, except about one day in 30.
- **Dry run by default**: nothing is sent, every decision is logged (7 days); "Dry-run day" replays the next 24 h at once; "Really send to the devices" in the configuration. A Simulation On period is excluded from the analysis.

## 0.12.1
- Fixes after the general review: two simultaneous saves (two tabs) run one after the other, and everything is checked before anything is erased; unreadable data refused instead of taken as "empty"; the page reloads the followed commands kept by the box.
- Guide: a duplicate whose kept command is not chosen yet does not count; changing your mind about a command puts its duplicates back in order; "Reset the Guide" also gives the commands their type back.
- Trends: a lamp left on (forgotten, stuck) is flagged "on without end" instead of erasing the following days; overlapping excluded periods merged; a dimmer no longer records each brightness change; a lamp switched on every evening around midnight recognised (23:58 and 00:02).
- Plan: plan version (another tab still on the old plan is refused), images of floors never saved erased after a day, 3 MB image at most, drawing kept when the room name or the passage is cancelled, marker details on a click (tablets), old Safari / iPad (image, grid, names); deleted absence command flagged; floor names with figures ("Lamp 1") no longer mean "1st floor".
- Selftest: administrator access and `.htaccess` checked, "//" in a string no longer hides a write; `.htaccess` for Apache 2.2 and 2.4.

## 0.12
- **Configuration** (plugin configuration) and **Widget** buttons at the top of the page: a "Usual presence" device (state, rooms usually on now, date of the trends, followed commands, Recompute), object and visibility adjustable; the rooms usually lit only if you tick the option (they hint at when the house is empty).
- Guide: **Enable their history…** for followed commands not historized or smoothed, on confirmation, previous setting kept and **Put the history back as before**; or the steps to do it by hand.
- Guide: **Presence sensors** (linked to Jeedom or not) and the lamps they switch on: no longer proposed as duplicates, marked "on sensor" in the Trends, kept to be simulated together; **＋ Jeedom sensor…** offers the Jeedom presence sensors (lamps of the same room pre-ticked); for a room on a sensor, "mostly on" is read against its most frequent hour (otherwise "at any hour").
- Plan: presence sensors linked to Jeedom can be placed like lamps; a marker placed outside the room given by its Jeedom room or its name is flagged (allowed).
- Fix: a virtual that copies a lamp stays its duplicate, even when both are on the same sensor (no longer pre-ticked among the sensor's lamps).
- Guide: **Reset the Guide** button (answers, rules, sensors and shutter direction cleared, on confirmation; the choice of commands, the plan and the records are kept; "Back to the previous choice" brings them back).

## 0.11
- **More readable trends**: a summary table (one row per room: hours when it is mostly on on weekdays and at weekends, hours when it never is, usual duration, quick switch-ons of a lamp on a sensor flagged), the hour-by-hour detail folded under each room (click its name), plain durations ("5 min", "1 h 57"), readable figures on dark cells, shutters: usual time in bold with the range, exceptions folded.

## 0.10
- **Plan**: everything can be edited or deleted (click a room, a passage or a zone: Rename / Edit / Delete panel, orange corners to drag; ✎ and 🗑 in the lists), deleting a room lists the passages that go with it, **Undo** button.
- Passages and staircases with more than two points ("Finish the shape"). A staircase between two floors is saved once and shown on both: on the other floor, first at the same place, pale and dashed "to adjust", until you drag its points. Staircases entered twice (once from each floor) are merged.
- **Jeedom rooms** (right column): the plan room of each Jeedom room, found from the name (ignoring accents, spaces and case) or chosen. **Place from the Jeedom rooms** button: followed lamps and shutters not yet placed go to their room; markers already placed do not move.
- Floor order from lowest to highest (numbered; ↑ = one level higher; in 0.9, ↑ moved up the list: check the order).
- **Guide**: rows grouped by Jeedom room (in plan order), source of each command (Virtual, Zigbee2MQTT…), filters by type and source; a virtual that copies another command is proposed as "Duplicate of" the original (kept); "Group of lamps": the lamps it switches on are ticked in a list, pre-filled by the discovery (list kept for the simulation, not yet used by the analysis).
- Guide: history of each command (📈 values kept over 90 days, Jeedom chart on click, "rich history" filter) and scenarios that control it (⚙, actions noted in their log) to help you choose; nothing is ticked for you.
- Fixes after review: a copying virtual no longer overrides a "No" nor the history of a followed virtual (proposed unticked, "to check"), "Undo" after saving no longer brings back a floor whose image was deleted, the lamps list of a group no longer offers those answered No or Duplicate, a duplicate of a group is refused, "Place from the Jeedom rooms" no longer puts a marker outside an L-shaped room, confirmation before erasing a staircase shown on both floors, plan edit made during saving kept; then history window title escaped, copy judged on exact records (not only logged), ranges being typed kept when histories load, smoothed history never "rich", "Duplicate of" button as strict as the discovery, markers of a full room kept inside.

## 0.9
- **Shutters of any brand**: text states (open / closed, up / down, ouvert / fermé; stop and my ignored instead of being taken as "closed"); direction set per shutter in the Guide (open = 0); shutters without generic type recognised by name by the discovery; shutter without state feedback (Somfy RTS…) flagged by the Guide after 3 days without change.
- "Identify by switching on or opening"; "may open" / "never open" ranges for a shutter.
- Inverted direction computed on the command scale (0-1 or 0-100); morning and evening of a shutter put in week or weekend at the time of the move; "3 days without change" counted outside absences, even for a shutter never logged; "auto", "mode", "calibration", "speed" commands of a shutter not proposed; the discovery no longer proposes a shutter already refused; a "shutter" that is the state of a daemon or a plugin ("Démon Volet proportionnel") is proposed unticked, "to check".

## 0.8.1
- Guide: the × of a range removes the right one; duplicate of a duplicate refused; duplicates follow the kept command when its answer changes; "Save" also keeps the plan; the page reloads what the box kept; range without a time refused; the discovery fills in the answers; "No" gives a plug its type back; question 4 aligned with the summary; "Identify by switching on" sees every binary or typed command.
- **Adjustable weekend** (configuration: start and end day and time), by default from Friday 16:00 to Monday 04:00: Sunday evening now counts as weekend.
- Going back to a choice from before 0.8 keeps the current answers and rules.
- Check added to the selftest: the plugin changes no device, command, scenario or history of the box.

## 0.8
- **Guide**: four questions (lamp or shutter of the house, duplicate or group; place on the plan; rules: never simulated, ranges when it may or must not switch on; commands without enough data), "Identify by switching on", and a summary that says when a simulation can be proposed. Nothing is started yet.

## 0.7.1
- Discover: "LED state" and other indicators of a plug are no longer taken for lamps; a device that already has a typed light does not offer its other binaries; switches on the period's bounds no longer count in duplicates and groups; duplicate chains resolved; latitude with a comma accepted.
- Days without light proposed unticked; lamps with smoothed history flagged.
- Watch: records kept 7 days after the end, no more orphans when watching again.
- The type chosen on the page is kept on reload; one discovery at a time.
- Confirmation before saving a choice that removes commands.

## 0.7
- **Discover**: proposals drawn from the last 30 days, to be confirmed: duplicates (one lamp seen by two commands), groups (such as Hue groups), lights without a generic type from their name, days without any light (possible absences), possible absence command, lights switched on at a fixed time or at sunset (flagged only); "Watch for 3 days" for commands without history.
- **Back to the previous choice**: reloads the choice from before the last save.
- Periods when the plugin simulates presence will be excluded from the analysis.

## 0.6
- **Plan** tab: one plan per floor (name, order, image kept on the box), lights and shutters placed with the mouse (or taken from a Jeedom design), rooms that group the lamps inside them, passages between rooms (door, corridor, staircase, including from one floor to another) with the observed sequences, zones excluded from the analysis; the markers show the trend at the chosen hour.
- Fixes after the 6th review: share of quick passages and scenes shown as 100 % when not always, smoothing that rounded 99 % to 100 %, "to be confirmed" missing from the documentation for lights.
- Fixes after the 7th review: floor image missing when opening the Plan tab, markers lost when saving the plan before the commands, image shown on the wrong floor, floor images erased by an unreadable plan, figures of one passage taken for another's, room mixed up with a typed group of the same name, marker taken from a design off-centre, "excluded zone" on the markers concerned, 200 corners at most per shape, plan data kept out of the repository.
- Fixes after the 8th review: ticked lamp not yet placeable on the plan before saving, room and typed group of the same name shown under the same title ("(room)" added), "j" not translated, two simultaneous saves getting in each other's way, centre of a widget with a width in % miscalculated, map left on the previous floor if the image fails to load.

## 0.5.1
- Fixes after the 5th review: shutter reopened after closing it against the heat taken for a morning opening, "to be confirmed" counted in days instead of noted values, two devices with the same name in two rooms mixed up (the room tells them apart), 100 % or 0 % shown when it is neither always nor never, inaccurate notice about the night before an absence; lights follow the same day (04:00 → 04:00) and weekend as shutters.

## 0.5
- Fixes after the 4th review: a shutter's evening counted although the next morning was an absence, "always" / "never closed" inferred from a rounded percentage, a maximum switch-on duration of 0 taken as 1 h (now 18 h), exception rule wrongly described, "to be confirmed" given for the whole row instead of weekdays or weekend, Save button active before the list was loaded.

## 0.4
- Fixes after the 3rd review: shutter times off by one hour on clock-change days, shutter with no observed night shown as "never closed", "never on" hours derived from a rounded percentage, default command selection re-applied after an empty save, state before the period not imported (a lamp switched on the day before was missed), unreadable command choice saved as "nothing".
- Hours when the box was stopped (over 3 h) are not counted; absences of the previous absence command are kept when it is changed; a smoothed numeric absence command is refused.

## 0.3
- Fixes after the 2nd review: a shutter's last night counted before it ended (wrongly "never closed"), days with the plugin disabled counted as an empty house, text absence command refused (it excluded nothing) and absence history imported in full, two switch-ons in the same minute merged, exact-history window wrongly widened, group named "#12" mixed up with lamp #12.

## 0.2
- Fixes after review: the followed commands were not read back correctly (nothing was analysed), disabling the plugin no longer empties the table, smoothed history imported only for its last hours (and flagged), abnormally long switch-ons discarded, unreadable entered period refused, absence command checked, a command added later is imported from history, “Erase all” button, trends kept in a file.
- Wording: the plugin does not “learn”, it draws trends from your history. Added: earliest, latest, average, shortest and longest durations, hours a lamp is never on, shutter never or always closed at night, exceptions (rare hours, days far from the middle half).

## 0.1
- First version: analysis only. Choice of followed lights and shutters (recommended by generic type), import of the Jeedom history, hour-by-hour profiles (weekday / weekend), durations, lamps switched on together, shutter times, absence periods excluded. Nothing leaves the box; no action on devices.
