# Writing for this site

Conventions for the articles in `content/`, for on-screen copy in
`templates/`, and for prose anywhere in the repository. `SPEC.md` §10 governs
the mechanics — front matter, dates, the title rendered once. This file covers
voice.

## Who the articles are for

**An implementer**: somebody metering their own content who is deciding
whether to do what this site does. §10.1's test for a subject is whether that
person would be worse off not knowing it.

So the person using the site is **"the reader"**, in the third person. "You"
means the implementer, or is absent. Quoted on-screen copy keeps its own
"you". `content/privacy.md` is the exception, addressed to the reader.

**One decision and its argument per piece.** Episodes, not transcripts. Show
rather than argue.

**Say why things are as they now are.** Side-tracks into this project's own
development journey — what went wrong, and when — are not the subject. A
rewrite early on was built around the specification and a diagram disagreeing:
a true story, well told, and not central. The subject is the decision and what
it means for the reader, not the artefacts that recorded it.

## The furniture

**Byline**, the body's first line, on every piece except `content/privacy.md`,
which is a site statement rather than somebody's writing:

```
*By Douglas Lovell with Claude <model> (Anthropic)*
```

**Naming sol-pay.** Link `sol-pay` on first mention, with no description after
it. Then "the sol-pay metering program" or "the sol-pay client library" on the
first reference needing to be more specific. Then "the metering program" and
"the client library". `initialize_site`, `meter_and_settle` and the rest are
**instructions of the one program**, not programs.

**Dates.** A draft rewritten before publishing needs `revised` set to the day
it is published. `bin/content-dates` compares the last commit with the claimed
date, and `created` cannot move past the first commit. The escape for a
touch-up is the `Reader-Visible: no` commit trailer.

## Prose rules

- **No run-on sentences joined with "and."** Break them into separate
  sentences.
- **Name the referent** where "it" or "that" could point at more than one
  thing.
- **"That" rather than "which."** "Which" belongs to non-restrictive clauses
  only.
- **Do not frame events as before or after 11 September**, and do not date
  them that way. The date carries an association nothing here intends. Be
  slightly inaccurate about the date, or point indirectly — "up to this
  writing" — when the page's date line already shows it.
- **Glance at any count in prose.** A count in text is easy to get wrong and
  invisible when it goes stale. This is a caution rather than a rule; "the
  last" is safe enough.
- **Watch the register.** "Prophylactic" was used of the analyser and read as
  the noun. "Preventative" is the word.

## On-screen text stays in the templates

Separating copy from code is for human-language translation, and no second
locale is coming. So the words live in `templates/`, and `PageTitleTest`
checks that the copies agree where one phrase is written twice. Extracting the
repeated words into constants was offered and declined.

The code itself should be code a human programmer would enjoy working with,
and would be happy to show colleagues.
