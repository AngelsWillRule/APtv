# OpenAP translations

OpenAP uses GNU Gettext. The current locale catalogs were inherited from
RaspAP and contain valuable translations contributed by the upstream language
community. Their authorship and license notices must be preserved.

OpenAP has since removed modules and introduced new interface text. Therefore:

- an inherited translation must not be presented as an OpenAP translation of
  a new or changed message;
- obsolete entries should be removed through standard Gettext tooling, not by
  editing every catalog with a global product-name replacement;
- OpenAP-specific strings fall back to English until reviewed translations are
  available;
- compiled `.mo` files must be generated from the reviewed `.po` files and
  must never be edited directly.

The publication candidate contains a freshly generated
`locale/messages.pot`. Its catalogs include only messages extracted from the
retained PHP source. Obsolete module entries have been removed, fuzzy matches
are retained for translator review but are not compiled, and every catalog
passes GNU Gettext syntax and format validation.

Install GNU Gettext and regenerate the template, merge catalogs, remove
obsolete entries and compile all `.mo` files with:

```bash
locale/update-translations.sh
```

To compile reviewed `.po` files without extracting or merging messages, run:

```bash
locale/pocompile.sh
```

OpenAP-specific messages currently fall back to English where no reviewed
translation exists. The project must not promise complete translation
coverage until those messages have been reviewed by speakers of each language.
