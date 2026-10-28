(function ($) {
    'use strict';
    var app = $('#document-editor');
    var form = $('#document-form');
    var billingMode = String(app.data('billing-mode') || 'SIMULACION');
    var internalMode = billingMode === 'INTERNO';
    var state = { products: [], related: [], row: 0, busy: false };

    function money(value) {
        return '$ ' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function showToast(message) {
        $('#app-toast-message').text(message);
        new bootstrap.Toast(document.getElementById('app-toast')).show();
    }
    function showError(message) {
        $('#document-alert').text(message).removeClass('d-none');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function selectedConditionCode() {
        return $('#document-condition option:selected').attr('data-code');
    }
    function selectedType() {
        return $('#document-type').val();
    }
    function isNote(type) {
        return type === '05' || type === '06';
    }
    function selectedRelatedType() {
        return String($('#related-document option:selected').attr('data-type') || '');
    }
    function priceMode() {
        var type = selectedType();
        if (type === '01' || type === 'IN') return 'included';
        if (type === '03') return 'added';
        if (isNote(type)) {
            var relatedType = selectedRelatedType();
            if (relatedType === '03') return 'added';
            if (relatedType === '01') return 'included';
            return 'unknown';
        }
        return 'none';
    }
    function updateCredit() {
        var credit = String(selectedConditionCode()) === '2';
        $('#credit-days-group').toggleClass('d-none', !credit);
        $('#credit-days').prop('disabled', !credit).prop('required', credit);
    }
    function updateContingencyReason() {
        var enabled = $('#contingency-check').is(':checked');
        var other = $('#contingency-type').val() === '5';
        $('#contingency-reason').prop('required', enabled && other);
        $('#contingency-reason-help').toggleClass('fw-semibold', enabled && other);
    }
    function updateContingencyFields() {
        if (!$('#contingency-check').length) return;
        var enabled = $('#contingency-check').is(':checked');
        $('#contingency-fields').toggleClass('d-none', !enabled);
        $('#contingency-note').toggleClass('d-none', !enabled);
        $('#contingency-type, #contingency-reason').prop('disabled', !enabled);
        $('#contingency-type').prop('required', enabled);
        $('#emit-document .button-label').text(
            internalMode ? 'Emitir comprobante' : (enabled ? 'Emitir en contingencia' : 'Emitir y transmitir')
        );
        updateContingencyReason();
    }
    function productById(id) {
        var found = null;
        $.each(state.products, function (_, product) { if (String(product.id) === String(id)) found = product; });
        return found;
    }
    function taxLabel(product) {
        if (!product) return '—';
        var type = selectedType();
        if (type === '11') return 'Exportación';
        if (type === '14') return 'Compra';
        if (!['01', '03', '05', '06', 'IN'].includes(type)) return '—';
        if (type === 'IN') {
            return product.tratamiento_iva === 'GRAVADO' ? 'Gravado (incluye IVA)'
                : (product.tratamiento_iva === 'EXENTO' ? 'Exento' : 'No sujeto');
        }
        if (isNote(type)) {
            var relatedType = selectedRelatedType();
            if (relatedType === '01') {
                return product.tratamiento_iva === 'GRAVADO' ? 'Gravado (incluye IVA)'
                    : (product.tratamiento_iva === 'EXENTO' ? 'Exento' : 'No sujeto');
            }
            if (relatedType === '03') {
                return product.tratamiento_iva === 'GRAVADO' ? 'Gravado (13 %)'
                    : (product.tratamiento_iva === 'EXENTO' ? 'Exento' : 'No sujeto');
            }
            return product.tratamiento_iva === 'GRAVADO' ? 'Gravado'
                : (product.tratamiento_iva === 'EXENTO' ? 'Exento' : 'No sujeto');
        }
        return product.tratamiento_iva === 'GRAVADO' ? 'Gravado (13 %)'
            : (product.tratamiento_iva === 'EXENTO' ? 'Exento' : 'No sujeto');
    }
    function updateItemTaxLabels() {
        $('#document-items-body tr').each(function () {
            var row = $(this);
            row.find('.item-tax').text(taxLabel(productById(row.find('.item-product').val())));
        });
    }
    function updateRelatedInfo() {
        var option = $('#related-document option:selected');
        if (!option.val()) {
            $('#related-document-info').text('Seleccione el cliente y el documento relacionado.');
            return;
        }
        var label = String(option.attr('data-type') || '') === '01' ? 'Factura' : 'Crédito Fiscal';
        $('#related-document-info').text(label + ' · ' + option.text().split(' · ').slice(1).join(' · ') + '. El modo de precio se ajusta al documento relacionado.');
    }
    function renderRelatedOptions() {
        var select = $('#related-document');
        var current = select.val();
        var clientId = $('#document-client').val();
        select.empty().append($('<option>').val('').text('Seleccione un documento procesado…'));
        $.each(state.related, function (_, document) {
            if (!clientId || String(document.cliente_id) !== String(clientId)) return;
            select.append($('<option>')
                .val(document.id)
                .attr('data-type', String(document.tipo_dte))
                .text(String(document.tipo_dte) + ' · ' + document.numero_control + ' · ' + document.fecha_emision));
        });
        if (current && select.find('option[value="' + current + '"]').length) {
            select.val(current);
        }
        updateRelatedInfo();
    }
    function updateType() {
        var type = selectedType();
        var note = isNote(type);
        var exportType = type === '11';
        var excluded = type === '14';

        $('#related-document-section').toggleClass('d-none', !note);
        $('#export-section').toggleClass('d-none', !exportType);
        $('#excluded-fields').toggleClass('d-none', !excluded);
        $('#withhold-iva-group').toggleClass('d-none', exportType || excluded);
        $('#withhold-iva').prop('disabled', exportType || excluded);
        if (exportType || excluded) $('#withhold-iva').prop('checked', false);

        $('#related-document').prop('required', note);
        $('#export-cod-pais, #export-nombre-pais, #export-tipo-persona, #export-tipo-item, #export-complemento')
            .prop('required', exportType).prop('disabled', !exportType);
        $('#export-incoterms, #export-desc-incoterms, #export-flete, #export-seguro, #export-no-onerosas')
            .prop('disabled', !exportType);
        $('#rete-renta').prop('required', excluded).prop('disabled', !excluded);

        var mode = priceMode();
        var help = mode === 'included'
            ? 'Los precios incluyen IVA; el IVA se separa en el resumen.'
            : (mode === 'added'
                ? 'Los precios no incluyen IVA; el IVA se agrega al total.'
                : (mode === 'unknown'
                    ? 'Seleccione el documento relacionado para fijar el tratamiento del IVA.'
                    : (excluded ? 'Sujeto excluido: los precios son compras y no llevan IVA.' : 'Exportación: precios netos, sin IVA.')));
        $('#price-mode-help').text(help);
        if (internalMode) {
            $('#price-mode-help').append(' El comprobante es de control interno y no tiene validez fiscal.');
        }

        var clientHelp = type === '03'
            ? 'Crédito Fiscal requiere NIT y correo electrónico.'
            : (['05', '06', '11', '14'].includes(type)
                ? 'Este tipo requiere un cliente con NIT o DUI.'
                : 'Factura admite consumidor final sin documento.');
        $('#client-document-help').text(clientHelp).toggleClass('text-danger', type === '03' || ['05', '06', '11', '14'].includes(type));

        $('#summary-taxed-label').text(excluded ? 'Compra' : 'Venta gravada');
        $('#summary-taxed-row, #summary-exempt-row, #summary-nonsubject-row').toggleClass('d-none', excluded || exportType);
        $('#summary-iva-row, #summary-withheld-row').toggleClass('d-none', exportType || excluded);
        $('#summary-rete-row').toggleClass('d-none', !excluded);
        $('#summary-export-row').toggleClass('d-none', !exportType);

        if (note) renderRelatedOptions();
        updateItemTaxLabels();
        recalculate();
    }
    function fieldName(index, name) { return 'items[' + index + '][' + name + ']'; }
    function productByBarcode(code) {
        var found = null;
        $.each(state.products, function (_, product) {
            if (String(product.codigo_barras || '').toUpperCase() === code) found = product;
        });
        return found;
    }
    function showBarcodeFeedback(message, ok) {
        $('#barcode-feedback').text(message)
            .toggleClass('is-error', !ok)
            .toggleClass('is-ok', ok);
    }
    function addProductLine(product) {
        closeProductPicker();
        var existing = null;
        var emptyRow = null;
        $('#document-items-body tr').each(function () {
            var row = $(this);
            if (String(row.find('.item-product').val()) === String(product.id)) {
                existing = row;
                return false;
            }
            if (emptyRow === null && !row.find('.item-product').val()) {
                emptyRow = row;
            }
        });
        if (existing !== null) {
            var quantity = Number(existing.find('.item-quantity').val()) || 0;
            existing.find('.item-quantity').val(quantity + 1);
            recalculate();
            return;
        }
        if (emptyRow !== null) {
            emptyRow.find('.item-quantity').val('1');
            emptyRow.find('.item-discount').val('0');
            setRowProduct(emptyRow, product.id, true);
            return;
        }
        addItem({
            producto_id: product.id,
            cantidad: '1',
            precio_unitario: product.precio_unitario,
            descuento: '0'
        });
    }
    function scanBarcode() {
        var input = $('#barcode-input');
        var code = String(input.val() || '').trim().toUpperCase();
        if (code === '') return;
        var product = productByBarcode(code);
        if (!product) {
            showBarcodeFeedback('No hay un producto activo con el código de barras ' + code + '.', false);
            input.trigger('select');
            return;
        }
        addProductLine(product);
        showBarcodeFeedback(product.codigo + ' · ' + product.descripcion + ' agregado a la factura.', true);
        input.val('').trigger('focus');
    }
    function productLabel(product) {
        return product.codigo + ' · ' + product.descripcion;
    }
    var picker = { input: null, row: null, items: [], active: -1 };
    if (!$('#product-picker-menu').length) {
        $('body').append($('<div>').attr({ id: 'product-picker-menu', role: 'listbox' }).addClass('product-picker-menu').prop('hidden', true));
    }
    function pickerMenu() {
        return $('#product-picker-menu');
    }
    function closeProductPicker() {
        var menu = pickerMenu();
        menu.prop('hidden', true).empty();
        if (picker.input) {
            picker.input.attr('aria-expanded', 'false');
        }
        picker.input = null;
        picker.row = null;
        picker.items = [];
        picker.active = -1;
    }
    function positionProductPicker() {
        if (!picker.input) return;
        var rect = picker.input[0].getBoundingClientRect();
        var menu = pickerMenu();
        var width = Math.min(Math.max(rect.width, 320), window.innerWidth - 16);
        var height = 280;
        var top = rect.bottom + 4;
        if (top + height > window.innerHeight - 8) {
            top = Math.max(8, rect.top - height - 4);
        }
        menu.css({
            left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)),
            top: top,
            width: width
        });
    }
    function productMatches(product, term) {
        if (term === '') return true;
        var haystack = (product.codigo + ' ' + product.descripcion + ' ' + (product.codigo_barras || '')).toUpperCase();
        return haystack.indexOf(term) !== -1;
    }
    function renderProductPicker(term) {
        var menu = pickerMenu().empty();
        var list = [];
        $.each(state.products, function (_, product) {
            if (productMatches(product, term)) list.push(product);
            if (list.length >= 60) return false;
        });
        picker.items = list;
        picker.active = list.length ? 0 : -1;
        if (!list.length) {
            menu.append($('<div>').addClass('product-picker-empty').text('No se encontraron productos con esa búsqueda.'));
        }
        $.each(list, function (index, product) {
            var item = $('<button>').attr({ type: 'button', role: 'option', 'data-index': index })
                .addClass('product-picker-item' + (index === 0 ? ' is-active' : ''));
            item.append($('<span>').addClass('product-picker-meta').text(money(product.precio_unitario)));
            item.append($('<span>').addClass('product-picker-code').text(product.codigo));
            item.append($('<span>').addClass('product-picker-name').text(product.descripcion));
            if (product.codigo_barras) {
                item.append($('<span>').addClass('product-picker-barcode').text('CB: ' + product.codigo_barras));
            }
            item.on('mousedown touchstart', function (event) {
                event.preventDefault();
                chooseProductPickerItem(index);
            });
            menu.append(item);
        });
        menu.prop('hidden', false);
        positionProductPicker();
    }
    function openProductPicker(input, term) {
        picker.input = input;
        picker.row = input.closest('tr');
        input.attr('aria-expanded', 'true');
        renderProductPicker(String(term || '').trim().toUpperCase());
    }
    function moveProductPicker(delta) {
        if (!picker.items.length) return;
        picker.active = (picker.active + delta + picker.items.length) % picker.items.length;
        var items = pickerMenu().find('.product-picker-item');
        items.removeClass('is-active').eq(picker.active).addClass('is-active');
        var active = items.eq(picker.active)[0];
        if (active && active.scrollIntoView) active.scrollIntoView({ block: 'nearest' });
    }
    function chooseProductPickerItem(index) {
        var product = picker.items[index];
        var row = picker.row;
        if (!product || !row) return;
        if (String(row.find('.item-product').val()) === String(product.id)) {
            closeProductPicker();
            return;
        }
        setRowProduct(row, product.id, true);
        closeProductPicker();
    }
    function setRowProduct(row, productId, replacePrice) {
        var select = row.find('.item-product');
        select.empty().append($('<option>').val('').text(''));
        select.append($('<option>').val(productId).text(productId));
        select.val(String(productId));
        updateRowProduct(row, replacePrice);
    }
    function addItem(values) {
        values = values || {};
        var index = state.row++;
        var row = $('<tr>').attr('data-row', index);
        var productCell = $('<td>');
        var select = $('<select>').addClass('item-product d-none').attr({
            name: fieldName(index, 'producto_id'),
            'aria-hidden': 'true',
            tabindex: '-1'
        });
        select.append($('<option>').val('').text(''));
        var search = $('<input>').addClass('form-control item-product-search').attr({
            type: 'text',
            placeholder: 'Código, descripción o código de barras…',
            autocomplete: 'off',
            spellcheck: 'false',
            role: 'combobox',
            'aria-expanded': 'false',
            'aria-controls': 'product-picker-menu',
            'aria-autocomplete': 'list',
            'aria-label': 'Producto o servicio'
        });
        productCell.append(search, select);
        row.append(productCell);
        row.append($('<td>').append($('<input>').addClass('form-control item-quantity').attr({ type: 'number', name: fieldName(index, 'cantidad'), min: '0.00000001', step: '0.00000001', value: values.cantidad || '1', required: true, 'aria-label': 'Cantidad' })));
        row.append($('<td>').append($('<input>').addClass('form-control item-price').attr({ type: 'number', name: fieldName(index, 'precio_unitario'), min: '0', step: '0.00000001', value: values.precio_unitario || '0', required: true, 'aria-label': 'Precio unitario' })));
        row.append($('<td>').append($('<input>').addClass('form-control item-discount').attr({ type: 'number', name: fieldName(index, 'descuento'), min: '0', step: '0.00000001', value: values.descuento || '0', required: true, 'aria-label': 'Descuento' })));
        row.append($('<td>').addClass('item-tax text-nowrap').text('—'));
        row.append($('<td>').addClass('item-total text-nowrap').text('$ 0.00'));
        row.append($('<td>').append($('<button>').addClass('btn btn-sm btn-outline-danger remove-item').attr({ type: 'button', 'aria-label': 'Quitar línea' }).text('Quitar')));
        $('#document-items-body').append(row);
        if (values.producto_id) {
            var picked = productById(values.producto_id);
            select.append($('<option>').val(values.producto_id).text(picked ? productLabel(picked) : 'Producto inactivo'))
                .val(String(values.producto_id));
            if (!picked) search.val('Producto inactivo');
        }
        updateRowProduct(row, values.precio_unitario === undefined);
        $('#items-empty').addClass('d-none');
        recalculate();
    }
    function updateRowProduct(row, replacePrice) {
        var product = productById(row.find('.item-product').val());
        if (product) {
            row.find('.item-product-search').val(productLabel(product));
            if (replacePrice) row.find('.item-price').val(product.precio_unitario);
        }
        row.find('.item-tax').text(taxLabel(product));
        recalculate();
    }
    function recalculate() {
        var type = selectedType();
        var mode = priceMode();
        var totals = { taxed: 0, exempt: 0, nonsubject: 0, discount: 0, iva: 0 };
        $('#document-items-body tr').each(function () {
            var row = $(this);
            var product = productById(row.find('.item-product').val());
            var quantity = Number(row.find('.item-quantity').val()) || 0;
            var price = Number(row.find('.item-price').val()) || 0;
            var discount = Number(row.find('.item-discount').val()) || 0;
            var gross = Math.max(0, quantity * price - discount);
            totals.discount += discount;

            if (!product) {
                row.find('.item-total').text('$ 0.00');
                return;
            }
            if (type === '11' || type === '14') {
                totals.taxed += gross;
                row.find('.item-total').text(money(gross));
                return;
            }
            if (product.tratamiento_iva === 'GRAVADO') {
                var net = gross;
                var iva = 0;
                var lineTotal = gross;
                if (mode === 'included') {
                    net = gross / 1.13;
                    iva = gross - net;
                } else if (mode === 'added') {
                    iva = net * 0.13;
                    lineTotal = gross + iva;
                }
                totals.taxed += net;
                totals.iva += iva;
                row.find('.item-total').text(money(lineTotal));
            } else if (product.tratamiento_iva === 'EXENTO') {
                totals.exempt += gross;
                row.find('.item-total').text(money(gross));
            } else {
                totals.nonsubject += gross;
                row.find('.item-total').text(money(gross));
            }
        });

        var note = isNote(type);
        var withheld = (type === '01' || type === '03' || note) && $('#withhold-iva').is(':checked') ? totals.taxed * 0.01 : 0;
        var reteRenta = type === '14' ? Number($('#rete-renta').val()) || 0 : 0;
        var flete = type === '11' ? Number($('#export-flete').val()) || 0 : 0;
        var seguro = type === '11' ? Number($('#export-seguro').val()) || 0 : 0;
        var operation = totals.taxed + totals.exempt + totals.nonsubject + totals.iva;
        var total = Math.max(0, operation - withheld - reteRenta + flete + seguro);

        $('#summary-taxed').text(money(totals.taxed));
        $('#summary-exempt').text(money(totals.exempt));
        $('#summary-nonsubject').text(money(totals.nonsubject));
        $('#summary-discount').text(money(totals.discount));
        $('#summary-iva').text(money(totals.iva));
        $('#summary-withheld').text('− ' + money(withheld));
        $('#summary-rete').text('− ' + money(reteRenta));
        $('#summary-export').text(money(flete + seguro));
        $('#summary-total').text(money(total));
        $('#summary-words').text('El monto en letras se generará y validará al guardar en el servidor.');
    }
    function setBusy(busy) {
        state.busy = busy;
        $('#save-draft, #emit-document').prop('disabled', busy);
        $('#save-draft .button-label, #emit-document .button-label').toggleClass('d-none', busy);
        $('#save-draft .button-loading, #emit-document .button-loading').toggleClass('d-none', !busy);
    }
    function saveAndMaybeEmit(emit) {
        if (state.busy) return;
        $('#document-alert, #items-error').addClass('d-none');
        FacturaSV.showFieldErrors(form, {});
        if (!form[0].checkValidity()) { form[0].reportValidity(); return; }
        if (!$('#document-items-body tr').length) { $('#items-error').text('Agregue al menos una línea.').removeClass('d-none'); return; }
        setBusy(true);
        $.ajax({ url: app.data('api'), method: 'POST', dataType: 'json', data: form.serialize() + '&action=save' })
            .done(function (response) {
                $('#document-id').val(response.id);
                var editUrl = new URL(window.location.href);
                editUrl.searchParams.set('id', response.id);
                window.history.replaceState({}, '', editUrl.toString());
                $('#document-form-title').text('Editar borrador');
                if (!emit) {
                    setBusy(false);
                    showToast(response.message);
                    return;
                }
                var emitData = { action: 'emit', id: response.id, csrf_token: $('meta[name="csrf-token"]').attr('content') };
                if ($('#contingency-check').is(':checked')) {
                    emitData.contingencia = '1';
                    emitData.tipo_contingencia = $('#contingency-type').val();
                    emitData.motivo_contin = $('#contingency-reason').val();
                }
                $.ajax({ url: app.data('api'), method: 'POST', dataType: 'json', data: emitData })
                    .done(function () { window.location.assign(app.data('list-url') + '?documento=' + response.id); })
                    .fail(function (xhr) {
                        setBusy(false);
                        var errorResponse = xhr.responseJSON || {};
                        FacturaSV.showFieldErrors(form, errorResponse.errors || {});
                        showError(errorResponse.message || 'El borrador se guardó, pero no fue posible emitirlo. Puede reintentarlo desde Documentos.');
                    });
            })
            .fail(function (xhr) {
                setBusy(false);
                var response = xhr.responseJSON || {};
                FacturaSV.showFieldErrors(form, response.errors || {});
                if (response.errors && response.errors.items) $('#items-error').text(response.errors.items).removeClass('d-none');
                showError(response.message || 'No fue posible guardar el documento.');
            });
    }
    function loadExisting(id) {
        $.ajax({ url: app.data('api'), method: 'GET', dataType: 'json', data: { action: 'get', id: id } })
            .done(function (response) {
                var document = response.document;
                if (document.estado !== 'BORRADOR') { showError('Solo se pueden editar borradores.'); setBusy(true); return; }
                $('#document-form-title').text('Editar borrador');
                $('#document-id').val(document.id);
                $('#document-type').val(document.tipo_dte);
                $('#document-client').val(document.cliente_id);
                $('#document-date').val(document.fecha_emision);
                $('#document-condition').val(document.condicion_operacion_id);
                $('#document-payment').val(document.forma_pago_id);
                $('#credit-days').val(document.dias_credito || 30);
                $('#issuer-district').val(document.emisor_distrito);
                $('#receiver-district').val(document.receptor_distrito);
                $('#withhold-iva').prop('checked', Number(document.aplicar_iva_retenido) === 1);
                $('#document-notes').val(document.observaciones || '');
                $('#export-cod-pais').val(document.export_cod_pais || '');
                $('#export-nombre-pais').val(document.export_nombre_pais || '');
                $('#export-tipo-persona').val(document.export_tipo_persona || '1');
                $('#export-tipo-item').val(document.export_tipo_item || '1');
                $('#export-complemento').val(document.export_complemento || '');
                $('#export-incoterms').val(document.export_incoterms || '');
                $('#export-desc-incoterms').val(document.export_desc_incoterms || '');
                $('#export-flete').val(document.export_flete || 0);
                $('#export-seguro').val(document.export_seguro || 0);
                $('#export-no-onerosas').val(document.export_no_onerosas || 0);
                $('#rete-renta').val(document.rete_renta || 0);
                $('#document-items-body').empty();
                $.each(response.items, function (_, item) { addItem(item); });
                renderRelatedOptions();
                $('#related-document').val(document.documento_relacionado_id || '');
                updateRelatedInfo();
                updateCredit();
                updateType();
            }).fail(function (xhr) { showError((xhr.responseJSON || {}).message || 'No fue posible cargar el borrador.'); setBusy(true); });
    }

    $.ajax({ url: app.data('api'), method: 'GET', dataType: 'json', data: { action: 'options' } })
        .done(function (response) {
            state.products = response.productos || [];
            state.related = response.documentos_relacionados || [];
            var editId = app.data('edit-id');
            if (editId) loadExisting(editId); else { addItem(); updateType(); }
            if (!state.products.length) showError('Primero registre y active productos o servicios en el catálogo.');
            if (!editId) $('#barcode-input').trigger('focus');
        }).fail(function () { showError('No fue posible cargar los productos y servicios.'); });

    $('#barcode-input').on('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            scanBarcode();
        }
    });
    $('#barcode-submit').on('click', scanBarcode);

    $('#document-condition').on('change', updateCredit);
    $('#document-type').on('change', updateType);
    $('#document-client').on('change', function () { renderRelatedOptions(); updateType(); });
    $('#related-document').on('change', function () { updateRelatedInfo(); updateType(); });
    $('#withhold-iva').on('change', recalculate);
    $('#export-flete, #export-seguro, #export-no-onerosas, #rete-renta').on('input change', recalculate);
    $('#document-items-body').on('focus', '.item-product-search', function () {
        var input = $(this);
        if (input.val()) input.select();
        openProductPicker(input, '');
    });
    $('#document-items-body').on('click', '.item-product-search', function () {
        var input = $(this);
        if (picker.input && picker.input[0] === input[0]) return;
        if (input.val()) input.select();
        openProductPicker(input, '');
    });
    $('#document-items-body').on('input', '.item-product-search', function () {
        var input = $(this);
        var row = input.closest('tr');
        row.find('.item-product').val('');
        row.find('.item-tax').text('—');
        recalculate();
        picker.input = input;
        picker.row = row;
        input.attr('aria-expanded', 'true');
        renderProductPicker(input.val().trim().toUpperCase());
    });
    $('#document-items-body').on('keydown', '.item-product-search', function (event) {
        var input = $(this);
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (pickerMenu().prop('hidden')) openProductPicker(input, input.val());
            else moveProductPicker(1);
            return;
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            if (!pickerMenu().prop('hidden')) moveProductPicker(-1);
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            closeProductPicker();
            return;
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            if (!pickerMenu().prop('hidden') && picker.active >= 0) {
                chooseProductPickerItem(picker.active);
                return;
            }
            var typed = input.val().trim().toUpperCase();
            var exact = null;
            $.each(state.products, function (_, product) {
                if (product.codigo.toUpperCase() === typed
                    || String(product.codigo_barras || '').toUpperCase() === typed
                    || productLabel(product).toUpperCase() === typed) {
                    exact = product;
                }
            });
            if (exact) {
                var currentId = input.closest('tr').find('.item-product').val();
                if (String(currentId) !== String(exact.id)) {
                    setRowProduct(input.closest('tr'), exact.id, true);
                }
                closeProductPicker();
            }
        }
    });
    $('#document-items-body').on('blur', '.item-product-search', function () {
        var input = $(this);
        window.setTimeout(function () {
            if (!picker.input || picker.input[0] !== input[0]) return;
            var row = input.closest('tr');
            var typed = input.val().trim().toUpperCase();
            var exact = null;
            $.each(state.products, function (_, product) {
                if (product.codigo.toUpperCase() === typed
                    || String(product.codigo_barras || '').toUpperCase() === typed
                    || productLabel(product).toUpperCase() === typed) {
                    exact = product;
                }
            });
            var selected = productById(row.find('.item-product').val());
            if (selected && (typed === productLabel(selected).toUpperCase()
                || typed === selected.codigo.toUpperCase()
                || typed === String(selected.codigo_barras || '').toUpperCase())) {
                input.val(productLabel(selected));
            } else if (exact) {
                setRowProduct(row, exact.id, true);
            } else {
                input.val(selected ? productLabel(selected) : '');
            }
            closeProductPicker();
        }, 120);
    });
    $(window).on('scroll resize', function () {
        if (picker.input) positionProductPicker();
    });
    $('#add-document-item').on('click', function () { addItem(); });
    $('#document-items-body').on('change', '.item-product', function () { updateRowProduct($(this).closest('tr'), true); });
    $('#document-items-body').on('input change', '.item-quantity, .item-price, .item-discount', recalculate);
    $('#document-items-body').on('click', '.remove-item', function () {
        var row = $(this).closest('tr');
        if (picker.row && picker.row[0] === row[0]) closeProductPicker();
        row.remove();
        $('#items-empty').toggleClass('d-none', $('#document-items-body tr').length > 0);
        recalculate();
    });
    $('#save-draft').on('click', function () { saveAndMaybeEmit(false); });
    $('#emit-document').on('click', function () { saveAndMaybeEmit(true); });
    form.on('submit', function (event) { event.preventDefault(); });
    $('#issuer-district, #receiver-district').on('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 2); });
    $('#export-cod-pais').on('input', function () { this.value = this.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2); });
    $('#contingency-check').on('change', updateContingencyFields);
    $('#contingency-type').on('change', updateContingencyReason);
    updateCredit();
    updateContingencyFields();
})(jQuery);
