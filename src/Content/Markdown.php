<?php

declare(strict_types=1);

namespace Newsprint\Content;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * What markdown means on this site.
 *
 * Extracted from `bin/build-content` on 2026-09-14, and the reason is the
 * defect that prompted it: **markdown is a dialect you assemble, and the
 * assembly had a hole in it that nothing could see.**
 *
 * Tables are not CommonMark. They are a GitHub extension, commonmark ships
 * them as an extension you have to register, and this build registered the
 * core and the front matter and nothing else — so a pipe table rendered as a
 * paragraph full of pipe characters. `privacy.md` carries the table §10.2 is
 * *about*, the list the page promises to print in full, and it had been
 * printing as pipes since it was written. Nothing failed: the build
 * succeeded, the page was served, the suite was green, and `site.css` had
 * carried `.body table` rules the whole time for markup nothing produced —
 * which is the quietest kind of dead code, because it reads as evidence that
 * the feature works.
 *
 * A build script cannot be asked whether its dialect is the one the content is
 * written in; that question needs a body and an assertion about the HTML. So
 * the dialect lives here, where `MarkdownTest` can put a fixture through it.
 * `bin/build-content` keeps the build — the front matter rules, the dates, the
 * manifest, the stale output — and hands the converting to this.
 */
final class Markdown
{
    public static function converter(): MarkdownConverter
    {
        $environment = new Environment([
            // The site's own markdown, so raw HTML in it would be the site's
            // own choice rather than a reader's — but §10.3 makes an embedded
            // script or a remote image a broken promise rather than a style
            // question, and the cheapest way to keep that promise is to make
            // it unrepresentable.
            //
            // The cost is worth stating, because it surfaces every time a
            // piece wants a picture: `<figure>` and `<figcaption>` are
            // unrepresentable too, so an illustration's description is its alt
            // text and the prose around it. See `ReadingTheInspector.md`.
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new FrontMatterExtension());

        // Not CommonMark; see the class docblock. `MarkdownTest` is what says
        // so in a form that goes red.
        $environment->addExtension(new TableExtension());

        // Not a syntax, which is why nothing caught it: `--` typed in a body
        // stayed two hyphens. See {@see EmDashParser}.

        $environment->addInlineParser(new EmDashParser());

        return new MarkdownConverter($environment);
    }
}
