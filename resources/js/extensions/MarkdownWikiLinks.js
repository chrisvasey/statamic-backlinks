export function attachMarkdownWikiLinks(editor, menu) {
    let active = null;
    let dismissed = null;

    function update() {
        const cursor = editor.getCursor();
        const before = editor.getLine(cursor.line).slice(0, cursor.ch);
        const match = before.match(/\[\[([^\[\]\n|]*)$/);
        const key = `${cursor.line}:${cursor.ch}:${before}`;
        if (editor.getOption('readOnly') || editor.somethingSelected() || !match || key === dismissed) {
            active = null;
            menu.close();
            return;
        }
        if (active?.key === key) return;
        const from = { line: cursor.line, ch: cursor.ch - match[0].length };
        active = { key, from, to: cursor };
        menu.update({
            query: match[1],
            clientRect: () => {
                const rect = editor.cursorCoords(cursor, 'window');
                return { ...rect, width: 0, height: rect.bottom - rect.top };
            },
            insert: title => {
                if (active?.key !== key) return;
                let end = cursor.ch;
                // CodeMirror automatically inserts closing brackets as the user types.
                const suffix = editor.getLine(cursor.line).slice(end).match(/^\]{1,2}/);
                if (suffix) end += suffix[0].length;
                active = null;
                editor.replaceRange(`[[${title}]]`, from, { line: cursor.line, ch: end });
                editor.setCursor({ line: from.line, ch: from.ch + title.length + 4 });
                editor.focus();
            },
        });
    }

    function keydown(cm, event) {
        if (!active) return;
        if (event.key === 'Escape') {
            dismissed = active.key;
            active = null;
            menu.close();
            event.preventDefault();
            event.stopPropagation();
        } else if (menu.onKeyDown(event)) {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    editor.on('cursorActivity', update);
    editor.on('change', update);
    editor.on('keydown', keydown);
    return () => {
        editor.off('cursorActivity', update);
        editor.off('change', update);
        editor.off('keydown', keydown);
        active = null;
        menu.close();
    };
}
