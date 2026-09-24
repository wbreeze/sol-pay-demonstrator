<?php

declare(strict_types=1);

namespace Newsprint\Content;

use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Two hyphens mean an em dash.
 *
 * This dialect has no smart-punctuation extension, so `--` typed in a body
 * rendered as two hyphens on the page. The same keystroke is correct in
 * `wasm-client/SPEC.md`, which becomes a PDF through a converter that folds it
 * into a dash, and wrong here — the same habit, right in one document and
 * wrong in the next, and no test could see the difference because two hyphens
 * are not a syntax.
 *
 * The alternative was a guard that refused a body containing `--`. This does
 * the thing the writer meant instead: **a run of exactly two hyphens becomes
 * `—`.** Spacing is preserved rather than imposed, so `a -- b` gives the
 * spaced dash this site uses throughout and a closed-up pair would stay closed
 * up.
 *
 * **A longer run is left alone.** `---` is a thematic break or a setext
 * underline at the start of a line and a front matter fence at the top of a
 * file, all of which are parsed as blocks before any inline parser runs; where
 * one appears mid-sentence, guessing at it would be worse than printing it.
 *
 * **Code is safe by construction rather than by a check here.** A code span and
 * a fenced block are parsed as literal text before inline parsing reaches
 * them, so `--no-dev` inside backticks never passes through this class.
 * `MarkdownTest` asserts that rather than trusting it, because it is the one
 * property whose failure would corrupt an instruction a reader is meant to
 * type.
 */
final class EmDashParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('-{2,}');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        if ($inlineContext->getFullMatch() !== '--') {
            return false;
        }

        $inlineContext->getCursor()->advanceBy(2);
        $inlineContext->getContainer()->appendChild(new Text('—'));

        return true;
    }
}
