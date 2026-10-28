(function ($) {
    'use strict';

    var app = $('#inventory-app');
    var api = app.data('api');
    var canWrite = String(app.data('can-write')) === '1';
    var TYPES = {
        ENTRADA: 'Entrada',
        SALIDA: 'Salida',
        AJUSTE_POSITIVO: 'Ajuste positivo',
        AJUSTE_NEGATIVO: 'Ajuste negativo'
    };
    var state = {
        products: [],
        page: 1,
        pages: 1,
        stockLoaded: false,
        movementsLoaded: false,
        kardexLoaded: false
    };

    function csrf() { return $('meta[name="csrf-token"]').attr('content'); }
    function showToast(message) {
        $('#app-toast-message').text(message);
        new bootstrap.Toast(document.getElementById('app-toast')).show();
    }
    function showAlert(message) { $('#inventory-alert').text(message).removeClass('d-none'); }
    function hideAlert() { $('#inventory-alert').addClass('d-none').text(''); }
    function get(action, data) {
        var payload;
        if (typeof data === 'string') {
            payload = 'action=' + encodeURIComponent(action) + (data ? '&' + data : '');
        } else {
            payload = $.extend({ action: action }, data || {});
        }
        return $.ajax({ url: api, method: 'GET', dataType: 'json', data: payload });
    }
    function post(action, data) {
        var payload;
        if (typeof data === 'string') {
            payload = (data ? data + '&' : '') + 'action=' + encodeURIComponent(action) +
                '&csrf_token=' + encodeURIComponent(csrf());
        } else {
            payload = $.extend({ action: action, csrf_token: csrf() }, data || {});
        }
        return $.ajax({ url: api, method: 'POST', dataType: 'json', data: payload });
    }
    function firstError(response) {
        var errors = (response || {}).errors || {};
        var values = Object.keys(errors).map(function (key) { return errors[key]; });
        return values.length ? values[0] : (response && response.message) || '';
    }
    function qty(value) {
        var number = Number(value || 0);
        try {
            return number.toLocaleString('es-SV', { minimumFractionDigits: 0, maximumFractionDigits: 4 });
        } catch (error) {
            return number.toFixed(4).replace(/\.?0+$/, '');
        }
    }
    function dateLabel(value) {
        if (!value) return '—';
        var parts = String(value).substring(0, 10).split('-');
        return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : String(value);
    }
    function todayISO() {
        var now = new Date();
        var month = String(now.getMonth() + 1).padStart(2, '0');
        var day = String(now.getDate()).padStart(2, '0');
        return now.getFullYear() + '-' + month + '-' + day;
    }
    function emptyRow(body, columns, message) {
        body.append($('<tr>').append($('<td>').attr('colspan', columns).addClass('report-empty').text(message)));
    }
    function inventoryStatus(status) {
        var badge = $('<span>').addClass('inventory-badge');
        if (status === 'DISPONIBLE') badge.addClass('is-ok').text('Disponible');
        else if (status === 'AGOTADO') badge.addClass('is-empty').text('Agotado');
        else if (status === 'NEGATIVO') badge.addClass('is-negative').text('Negativo');
        else badge.text(status || '—');
        return badge;
    }
    function typeBadge(type) {
        var badge = $('<span>').addClass('inventory-badge');
        if (type === 'ENTRADA') badge.addClass('is-entry').text('Entrada');
        else if (type === 'SALIDA') badge.addClass('is-exit').text('Salida');
        else if (type === 'AJUSTE_POSITIVO') badge.addClass('is-adjust').text('Ajuste +');
        else if (type === 'AJUSTE_NEGATIVO') badge.addClass('is-adjust-negative').text('Ajuste −');
        else badge.text(TYPES[type] || type);
        return badge;
    }
    function productLabel(product) {
        return product.codigo + ' — ' + product.descripcion + (Number(product.activo) === 1 ? '' : ' (inactivo)');
    }
    function initializeRanges() {
        var today = todayISO();
        var first = today.substring(0, 8) + '01';
        ['movement', 'kardex'].forEach(function (prefix) {
            var from = $('#' + prefix + '-from');
            var to = $('#' + prefix + '-to');
            if (from.length && !from.val()) from.val(first);
            if (to.length && !to.val()) to.val(today);
        });
    }

    function fillProductSelects() {
        var filter = $('#movement-product-filter').empty();
        filter.append($('<option>').val('').text('Todos'));
        var kardex = $('#kardex-product').empty();
        var modal = $('#movement-product').empty();
        modal.append($('<option>').val('').text('Seleccione…'));
        $.each(state.products, function (_, product) {
            var label = productLabel(product);
            filter.append($('<option>').val(product.id).text(label));
            kardex.append($('<option>').val(product.id).text(label));
            modal.append($('<option>').val(product.id).text(label + ' (' + qty(product.stock_actual) + ')'));
        });
        if (!$('#kardex-product').val() && state.products.length) {
            $('#kardex-product').val(state.products[0].id);
        }
    }

    function loadProducts() {
        return get('productos').done(function (response) {
            state.products = response.items || [];
            fillProductSelects();
            if (state.kardexLoaded) loadKardex();
        });
    }

    function renderResumen(data) {
        $('#inv-kpi-products').text(String(data.productos_controlados || 0));
        $('#inv-kpi-units').text(qty(data.unidades));
        $('#inv-kpi-negative').text(String(data.negativos || 0));
        $('#inv-kpi-movements').text(String(data.movimientos_mes || 0));
        $('#inv-kpi-start').text(data.fecha_inicio ? 'Desde ' + dateLabel(data.fecha_inicio) : '—');
        $('#inv-kpi-period').text(data.periodo ? dateLabel(data.periodo.desde) + ' — ' + dateLabel(data.periodo.hasta) : '—');
        $('#inv-start').text(data.fecha_inicio ? dateLabel(data.fecha_inicio) : '—');
        $('#inv-empty').text(String(data.agotados || 0));
        $('#inv-negative-count').text(String(data.negativos || 0));
        $('#inv-units').text(qty(data.unidades));

        if (Number(data.negativos || 0) > 0) {
            $('#inventory-negative-text').text(Number(data.negativos) + (Number(data.negativos) === 1
                ? ' producto tiene existencia negativa. Revise si falta registrar una entrada o un ajuste.'
                : ' productos tienen existencia negativa. Revise si faltan entradas o ajustes.'));
            $('#inventory-negative').removeClass('d-none');
        } else {
            $('#inventory-negative').addClass('d-none');
        }

        var pending = Number(data.pendientes || 0);
        if (pending > 0) {
            var action = $('#inventory-sync-banner').length
                ? ' Ejecute “Reconciliar ventas” para generarlos.'
                : ' Solicite a un administrador que ejecute la reconciliación.';
            $('#inventory-pending-text').text(pending + (pending === 1
                ? ' documento procesado o invalidado aún no tiene su movimiento de inventario.'
                : ' documentos procesados o invalidados aún no tienen su movimiento de inventario.') + action);
            $('#inventory-pending').removeClass('d-none');
        } else {
            $('#inventory-pending').addClass('d-none');
        }

        var body = $('#inv-recent-body').empty();
        if (!data.recientes || !data.recientes.length) {
            emptyRow(body, 5, 'Todavía no hay movimientos de inventario.');
            return;
        }
        $.each(data.recientes, function (_, movement) {
            var row = $('<tr>');
            row.append($('<td>').addClass('text-nowrap').text(dateLabel(movement.fecha)));
            row.append($('<td>').text(movement.producto_codigo + ' ' + movement.producto_descripcion));
            row.append($('<td>').append(typeBadge(movement.tipo)));
            row.append($('<td>').addClass('text-end ' + (movement.cantidad_firmada < 0 ? 'inventory-negative-value' : 'inventory-positive-value'))
                .text((movement.cantidad_firmada > 0 ? '+' : '') + qty(movement.cantidad_firmada)));
            row.append($('<td>').text(movement.motivo));
            body.append(row);
        });
    }

    function loadResumen() {
        get('resumen').done(renderResumen).fail(function (xhr) {
            showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar el resumen de inventario.');
        });
    }

    function renderExistencias(data) {
        var body = $('#stock-body').empty();
        if (!data.items || !data.items.length) {
            emptyRow(body, 6, 'No hay productos con control de existencias para los filtros seleccionados.');
            return;
        }
        $.each(data.items, function (_, product) {
            var row = $('<tr>');
            row.append($('<td>').text(product.codigo));
            row.append($('<td>').text(product.descripcion));
            row.append($('<td>').text(product.unidad_nombre + ' (' + product.unidad_codigo + ')'));
            row.append($('<td>').addClass('text-end fw-semibold ' + (product.stock_actual < 0 ? 'inventory-negative-value' : ''))
                .text(qty(product.stock_actual)));
            row.append($('<td>').append(inventoryStatus(product.estado)));
            row.append($('<td>').addClass('text-nowrap').text(product.ultimo_movimiento ? dateLabel(product.ultimo_movimiento) : '—'));
            body.append(row);
        });
    }

    function loadStock() {
        $('.inventory-filters-loading').remove();
        get('existencias', $('#stock-filters').serialize()).done(function (response) {
            renderExistencias(response);
            state.stockLoaded = true;
        }).fail(function (xhr) {
            showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar las existencias.');
        });
    }

    function renderMovements(data) {
        var body = $('#movements-body').empty();
        if (!data.items || !data.items.length) {
            emptyRow(body, 7, 'No hay movimientos para los filtros seleccionados.');
        } else {
            $.each(data.items, function (_, movement) {
                var row = $('<tr>');
                row.append($('<td>').addClass('text-nowrap').text(dateLabel(movement.fecha)));
                row.append($('<td>').text(movement.producto_codigo + ' ' + movement.producto_descripcion));
                row.append($('<td>').append(typeBadge(movement.tipo)));
                row.append($('<td>').addClass('text-end ' + (movement.cantidad_firmada < 0 ? 'inventory-negative-value' : 'inventory-positive-value'))
                    .text((movement.cantidad_firmada > 0 ? '+' : '') + qty(movement.cantidad_firmada)));
                row.append($('<td>').text(movement.motivo));
                row.append($('<td>').addClass('text-nowrap').text(movement.numero_control || '—'));
                row.append($('<td>').text(movement.usuario || 'Sistema'));
                body.append(row);
            });
        }
        state.page = data.pagination ? data.pagination.page : 1;
        state.pages = data.pagination ? data.pagination.pages : 1;
        $('#movements-page-label').text('Página ' + state.page + ' de ' + state.pages + ' · ' +
            (data.pagination ? data.pagination.total : 0) + ' movimientos');
        $('#movements-totals').text('Entradas: ' + qty(data.total_entradas) + ' · Salidas: ' + qty(data.total_salidas));
        $('#movements-prev').prop('disabled', state.page <= 1);
        $('#movements-next').prop('disabled', state.page >= state.pages);
    }

    function loadMovements() {
        var data = $('#movement-filters').serialize() + '&page=' + state.page;
        get('movimientos', data).done(function (response) {
            renderMovements(response);
            state.movementsLoaded = true;
        }).fail(function (xhr) {
            showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar los movimientos.');
        });
    }

    function renderKardex(data) {
        var body = $('#kardex-body').empty();
        $('#kardex-product-label').text(data.producto ? data.producto.codigo + ' ' + data.producto.descripcion : '');
        body.append($('<tr>').addClass('inventory-opening-row').append(
            $('<td>').attr('colspan', 5).text('Saldo inicial'),
            $('<td>').addClass('text-end').text(qty(data.saldo_inicial))
        ));
        if (!data.rows || !data.rows.length) {
            emptyRow(body, 6, 'No hay movimientos en el periodo; el saldo inicial es la existencia actual.');
        } else {
            $.each(data.rows, function (_, row) {
                body.append($('<tr>').append(
                    $('<td>').addClass('text-nowrap').text(dateLabel(row.fecha)),
                    $('<td>').append(typeBadge(row.tipo)),
                    $('<td>').addClass('text-end ' + (row.cantidad_firmada < 0 ? 'inventory-negative-value' : 'inventory-positive-value'))
                        .text((row.cantidad_firmada > 0 ? '+' : '') + qty(row.cantidad_firmada)),
                    $('<td>').text(row.motivo),
                    $('<td>').text(row.numero_control || '—'),
                    $('<td>').addClass('text-end fw-semibold').text(qty(row.saldo))
                ));
            });
        }
        $('#kardex-opening').text(qty(data.saldo_inicial));
        $('#kardex-entries').text(qty(data.total_entradas));
        $('#kardex-exits').text(qty(data.total_salidas));
        $('#kardex-final').text(qty(data.saldo_final));
        state.kardexLoaded = true;
    }

    function loadKardex() {
        var productId = $('#kardex-product').val();
        if (!productId) {
            $('#kardex-body').empty();
            emptyRow($('#kardex-body'), 6, 'Seleccione un producto con control de existencias.');
            return;
        }
        get('kardex', $('#kardex-filters').serialize()).done(renderKardex).fail(function (xhr) {
            showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar el kardex.');
        });
    }

    function openMovementModal() {
        var form = $('#movement-form')[0];
        form.reset();
        FacturaSV.showFieldErrors($('#movement-form'), {});
        $('#movement-alert').addClass('d-none').text('');
        $('#movement-date').val(todayISO());
        $('#movement-type').val('ENTRADA');
        $('#movement-product').val('');
        $('#movement-current').val('');
        $('#movement-stock-hint').text('Seleccione un producto para ver su existencia actual.');
        new bootstrap.Modal(document.getElementById('movement-modal')).show();
    }

    function updateMovementHint() {
        var id = Number($('#movement-product').val() || 0);
        var product = state.products.filter(function (item) { return Number(item.id) === id; })[0];
        if (!product) {
            $('#movement-current').val('');
            $('#movement-stock-hint').text('Seleccione un producto para ver su existencia actual.');
            return;
        }
        $('#movement-current').val(qty(product.stock_actual));
        $('#movement-stock-hint').text('Existencia actual: ' + qty(product.stock_actual) + ' · ' + product.unidad_nombre + ' (' + product.unidad_codigo + ').');
    }

    function exportReport(report) {
        var params;
        if (report === 'kardex') {
            params = $('#kardex-filters').serialize();
            if (!$('#kardex-product').val()) {
                showAlert('Seleccione un producto para exportar el kardex.');
                return;
            }
        } else if (report === 'movimientos') {
            params = $('#movement-filters').serialize();
        } else {
            params = $('#stock-filters').serialize();
        }
        window.location.assign(api + '?action=export&report=' + encodeURIComponent(report) + '&' + params);
    }

    $('#stock-filters').on('submit', function (event) {
        event.preventDefault();
        loadStock();
    });
    $('#movement-filters').on('submit', function (event) {
        event.preventDefault();
        state.page = 1;
        loadMovements();
    });
    $('#kardex-filters').on('submit', function (event) {
        event.preventDefault();
        loadKardex();
    });
    $('#movements-prev').on('click', function () {
        if (state.page <= 1) return;
        state.page--;
        loadMovements();
    });
    $('#movements-next').on('click', function () {
        if (state.page >= state.pages) return;
        state.page++;
        loadMovements();
    });
    $('[data-export]').on('click', function () { exportReport(String($(this).data('export'))); });

    $('#movement-new, #inv-new-movement').on('click', function () {
        if (!canWrite) return;
        openMovementModal();
    });
    $('#movement-product').on('change', updateMovementHint);
    $('#movement-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        var element = form[0];
        if (!element.checkValidity()) {
            element.reportValidity();
            return;
        }
        $('#movement-alert').addClass('d-none').text('');
        FacturaSV.showFieldErrors(form, {});
        FacturaSV.setLoading(form, true);
        post('movimiento-create', form.serialize()).done(function (response) {
            FacturaSV.setLoading(form, false);
            bootstrap.Modal.getInstance(document.getElementById('movement-modal')).hide();
            showToast(response.message);
            loadProducts();
            loadResumen();
            if (state.stockLoaded) loadStock();
            if (state.movementsLoaded) loadMovements();
            if (state.kardexLoaded) loadKardex();
        }).fail(function (xhr) {
            FacturaSV.setLoading(form, false);
            var response = xhr.responseJSON || {};
            FacturaSV.showFieldErrors(form, response.errors || {});
            $('#movement-alert').text(firstError(response) || 'No fue posible guardar el movimiento.').removeClass('d-none');
        });
    });

    function syncInventory(button) {
        button.prop('disabled', true);
        $('#inv-sync, #inventory-sync-banner').prop('disabled', true);
        post('sincronizar').done(function (response) {
            $('#inv-sync, #inventory-sync-banner').prop('disabled', false);
            showToast(response.message);
            loadProducts();
            loadResumen();
            if (state.stockLoaded) loadStock();
            if (state.movementsLoaded) loadMovements();
            if (state.kardexLoaded) loadKardex();
        }).fail(function (xhr) {
            $('#inv-sync, #inventory-sync-banner').prop('disabled', false);
            showAlert((xhr.responseJSON || {}).message || 'No fue posible reconciliar las ventas.');
        });
    }

    $('#inv-sync, #inventory-sync-banner').on('click', function () {
        syncInventory($(this));
    });

    $('[data-bs-toggle="tab"]').on('shown.bs.tab', function (event) {
        var target = String($(event.target).data('bs-target') || '');
        if (target === '#tab-existencias') loadStock();
        if (target === '#tab-movimientos' && !state.movementsLoaded) loadMovements();
        if (target === '#tab-kardex') loadKardex();
    });

    function loadBootstrap() {
        hideAlert();
        initializeRanges();
        get('productos').done(function (response) {
            state.products = response.items || [];
            fillProductSelects();
            loadResumen();
            loadMovements();
        }).fail(function (xhr) {
            showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar los productos con inventario.');
        });
    }

    loadBootstrap();
})(jQuery);
