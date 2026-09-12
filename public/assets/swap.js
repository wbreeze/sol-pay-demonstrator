/**
 * Put a server's answer where the thing that asked for it is.
 *
 * Two controls send a POST and get back the same shape of answer: the article
 * shell (`read-on.js`), whose POST meters the view, and the seven-view advance
 * (`advance.js`), whose POST charges seven of them. Both answers are the
 * article and the inspector, rendered by the request that did the work — which
 * is the property the whole arrangement rests on. **Nothing on the page
 * outlives the swap**: the body, the meter strip and the panel all arrive
 * together, from one request, so there is no older state left beside them. An
 * in-place re-render that updated only part of the page was tried for the
 * advance on 2026-09-10 and dropped for exactly that reason.
 *
 * This module is the mechanics only. What to say while waiting, and what to do
 * when it fails, belong to the control that asked.
 */

/**
 * Scripts inserted as markup do not run, and an answer can carry two: the
 * advance control's own, and — on the `set_meter` screens — the wallet's. A
 * fresh element with the same attributes does run.
 *
 * **Once per URL, and that is the browser's rule rather than a choice made
 * here.** A module is evaluated once per document, keyed by its URL; a second
 * `<script type="module" src="…">` for a URL already in that map is fetched
 * from cache, if at all, and never executed. So a module that binds a listener
 * to the element it found at load time works on the first answer and is dead
 * on every answer after it. That is not theoretical: the advance's second
 * click in one visit fell back to a full-page form submit, and the capture of
 * 2026-09-11 22:22 has it — one advance answered as a fragment, the next
 * answered `303`.
 *
 * Re-inserting it cannot help, so this does not pretend to: a module already
 * run is dropped rather than re-added, and the scripts that a swap can deliver
 * more than once listen from the document instead of from the node
 * (`advance.js`). `newsprint:swapped` is fired for anything that has to know.
 */
const loaded = new Set();

function remember() {
    for (const script of document.querySelectorAll('script[type="module"][src]')) {
        loaded.add(script.src);
    }
}

function activate(root) {
    for (const inert of root.querySelectorAll('script')) {
        const module = inert.type === 'module' && inert.src !== '';
        if (module && loaded.has(new URL(inert.getAttribute('src'), document.baseURI).href)) {
            inert.remove();
            continue;
        }

        const live = document.createElement('script');
        for (const { name, value } of inert.attributes) live.setAttribute(name, value);
        live.textContent = inert.textContent;
        inert.replaceWith(live);
        if (module) loaded.add(live.src);
    }
}

/**
 * Ask for a fragment, and replace the article and the inspector with it.
 *
 * Throws rather than reporting: the caller has a place to put the message and
 * a button to offer back. A throw here means nothing was replaced.
 */
export async function swap(action, body = undefined) {
    const response = await fetch(action, {
        method: 'POST',
        headers: { 'X-Fragment': '1' },
        body,
    });
    if (!response.ok) throw new Error(`the site answered ${response.status}`);

    const answer = document.createElement('template');
    answer.innerHTML = await response.text();
    const article = answer.content.querySelector('article.piece');
    const inspector = answer.content.querySelector('.inspector-body');
    if (!article) throw new Error('the answer had no article in it');

    // Before the old article goes: what it loaded is what cannot load again.
    remember();

    document.querySelector('article.piece').replaceWith(article);
    activate(article);

    const here = document.querySelector('details.inspector .inspector-body');
    if (inspector && here) {
        here.replaceWith(inspector);
        // `assets/inspector.js` finds its rows when the panel is opened; this
        // tells it the panel's contents changed underneath it, in case the
        // reader had it open while the POST was out.
        document.dispatchEvent(new CustomEvent('newsprint:inspector'));
    }

    // For anything that bound to what is no longer on the page.
    document.dispatchEvent(new CustomEvent('newsprint:swapped'));
}
