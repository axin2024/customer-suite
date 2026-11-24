for (const editor of document.querySelectorAll('[data-products]')) {
    const rows = editor.querySelector('[data-product-rows]');
    const template = editor.querySelector('[data-product-template]');
    const add = editor.querySelector('[data-add-product]');

    function numberRows() {
        const items = Array.from(rows.querySelectorAll('[data-product-row]'));
        for (const [index, item] of items.entries()) {
            for (const field of item.querySelectorAll('[data-product-field]')) {
                field.name = `data[products][${index}][${field.dataset.productField}]`;
            }
        }
        add.disabled = items.length >= 100;
    }

    add.addEventListener('click', () => {
        rows.append(template.content.cloneNode(true));
        numberRows();
        rows.lastElementChild.querySelector('input').focus();
    });
    rows.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-product]');
        if (remove) {
            remove.closest('[data-product-row]').remove();
            numberRows();
            add.focus();
        }
    });
    numberRows();
}
