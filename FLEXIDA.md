# Flexida — Grafida with FLEXIcontent support

Flexida is a fork of [Grafida](https://github.com/grafida/grafida) (© Nicholas K. Dionysopoulos, GPL-3.0-or-later)
that lets you write and edit articles on **Joomla sites that use FLEXIcontent**, not only plain Joomla.

> **Experimental.** Unofficial fork; not affiliated with or endorsed by the Grafida author. Tested against
> Joomla 6.2 + FLEXIcontent 5.1 and plain Joomla 6.2 only. Please report problems here, not to the Grafida project.

## What it adds

- **Content type** and **secondary categories** of a FLEXIcontent item, plus tags.
- The type's **custom fields**: text, textarea, date, e-mail, colour, choice fields (select, multi-select, radio,
  checkbox), web link, shared media (YouTube / Vimeo), single pictures, **galleries** (several pictures at once),
  and **files** (several at once).
- A roomier editor: tabs **Content / Fields & media / Settings**, each field as a card you can fold, a warning when
  no category is chosen before publishing.
- Other FLEXIcontent field types (relation, address, phone, field groups …) keep their stored value when you edit
  an item; they cannot be edited yet.

On a site **without** FLEXIcontent it behaves like Grafida.

## Requirements

1. The Joomla site needs the **[Flexida plugin for Joomla](https://github.com/lungkao/flexida-joomla-plugin)**
   installed and enabled (once per site). Without it the app falls back to plain-Joomla behaviour.
2. HTTPS and a Joomla API token, as for Grafida itself.

## Status of this repository

- This branch contains the changes on top of Grafida `main` (see `git log`): `src/Flexi/`, the editor changes in
  `assets/private/`, API client and publish changes, new unit tests.
- **No installable app packages yet.** Build them like Grafida (`composer build`; see the Grafida docs); the first
  trial packages are not published.
- Known issue: on macOS 27 the native window crashed intermittently during development (Boson 0.19.5, inside the
  scheme handler); it was not reproducible later and the cause is unknown.

## Licence

GNU General Public License version 3 or later, as Grafida. All upstream copyright notices are kept.
