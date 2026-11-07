# Changelog

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
