(function ($) {
    'use strict';

    var app = $('#products-app');
    var canWrite = Number(app.data('can-write')) === 1;
    var canDelete = Number(app.data('can-delete')) === 1;
    var productModal = canWrite ? new bootstrap.Modal(document.getElementById('product-modal')) : null;
    var state = {
        page: 1,
        pages: 1,
        units: [],
        taxLabels: {},
        request: null
    };

    var optionsRequest = $.ajax({
        url: app.data('api'), method: 'GET', dataType: 'json', data: { action: 'options' }
    }).done(function (response) {
        state.units = response.unidades_medida;
        state.taxLabels = response.tratamientos_iva;
        fillUnits('');
    });

    function showToast(message) {
        $('#app-toast-message').text(message);
        new bootstrap.Toast(document.getElementById('app-toast')).show();
    }

    function showError(message) {
        $('#product-alert').text(message).removeClass('d-none');
    }

    function fillUnits(selected) {
        var select = $('#product-unit').empty();
        select.append($('<option>').val('').text('Seleccione…'));
        $.each(state.units, function (_, unit) {
            select.append($('<option>').val(unit.id).text(unit.codigo + ' — ' + unit.nombre));
        });
        select.val(selected || '');
    }

    function updateStockField() {
        var enabled = $('#product-stock-control').is(':checked');
        var editing = Boolean($('#product-id').val());
        $('#product-stock-group').toggleClass('d-none', !enabled);
        $('#product-stock-control').prop('disabled', editing);
        $('#product-stock').prop('disabled', !enabled || editing).prop('required', enabled && !editing);
        $('#product-stock-note').toggleClass('d-none', !editing || !enabled);
        if (!enabled) {
            $('#product-stock').val('');
        }
    }

    function formatDecimal(value, maximumDigits) {
        return Number(value).toLocaleString('es-SV', {
            minimumFractionDigits: 0,
            maximumFractionDigits: maximumDigits,
            useGrouping: false
        });
    }

    function renderRows(items) {
        var body = $('#products-table-body').empty();
        if (!items.length) {
            body.append($('<tr>').append(
                $('<td>').attr('colspan', 8).addClass('text-center text-muted py-4')
                    .text('No se encontraron productos o servicios con los filtros seleccionados.')
            ));
            return;
        }

        $.each(items, function (_, product) {
            var row = $('<tr>');
            var codeCell = $('<td>');
            codeCell.append($('<span>').addClass('code-badge').text(product.codigo));
            if (product.codigo_barras) {
                codeCell.append($('<span>').addClass('product-secondary').text('CB: ' + product.codigo_barras));
            }
            row.append(codeCell);
            row.append($('<td>').append($('<strong>').addClass('product-description').text(product.descripcion)));
            var kind = $('<td>');
            kind.append($('<span>').text(product.tipo === 'BIEN' ? 'Bien' : 'Servicio'));
            kind.append($('<span>').addClass('product-secondary').text(product.unidad_nombre));
            row.append(kind);
            row.append($('<td>').addClass('text-nowrap').text('$ ' + formatDecimal(product.precio_unitario, 6)));
            row.append($('<td>').text(state.taxLabels[product.tratamiento_iva] || product.tratamiento_iva));
            row.append($('<td>').text(Number(product.control_stock) === 1
                ? formatDecimal(product.stock, 4)
                : 'No controlado')
                .toggleClass('text-danger fw-semibold', Number(product.control_stock) === 1 && Number(product.stock) < 0));
            row.append($('<td>').append(
                $('<span>').addClass(Number(product.activo) === 1 ? 'status-active' : 'status-inactive')
                    .text(Number(product.activo) === 1 ? 'Activo' : 'Inactivo')
            ));

            if (canWrite) {
                var actions = $('<td>').addClass('text-end text-nowrap');
                actions.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-outline-primary me-1 edit-product')
                    .attr('data-id', product.id).attr('aria-label', 'Editar ' + product.descripcion).text('Editar'));
                if (canDelete) {
                    actions.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-outline-danger delete-product')
                        .attr('data-id', product.id).attr('aria-label', 'Eliminar ' + product.descripcion).text('Eliminar'));
                }
                row.append(actions);
            }
            body.append(row);
        });
    }

    function renderPagination(pagination) {
        state.page = Number(pagination.page);
        state.pages = Number(pagination.pages);
        var start = pagination.total === 0 ? 0 : ((state.page - 1) * pagination.per_page) + 1;
        var end = Math.min(state.page * pagination.per_page, pagination.total);
        $('#products-pagination-summary').text('Mostrando ' + start + '–' + end + ' de ' + pagination.total + ' registros');
        $('#products-pagination-page').text('Página ' + state.page + ' de ' + state.pages);
        $('#products-page-prev').prop('disabled', state.page <= 1);
        $('#products-page-next').prop('disabled', state.page >= state.pages);
    }

    function loadProducts() {
        if (state.request) {
            state.request.abort();
        }
        $('#product-alert').addClass('d-none');
        state.request = $.ajax({
            url: app.data('api'), method: 'GET', dataType: 'json',
            data: {
                action: 'list', page: state.page, search: $('#product-search').val(),
                tipo: $('#product-type').val(), tratamiento_iva: $('#product-tax').val(),
                status: $('#product-status').val()
            }
        }).done(function (response) {
            renderRows(response.items);
            renderPagination(response.pagination);
        }).fail(function (xhr, textStatus) {
            if (textStatus === 'abort') {
                return;
            }
            var response = xhr.responseJSON || {};
            renderRows([]);
            showError(response.message || 'No fue posible cargar los productos y servicios.');
        });
    }

    function openCreate() {
        $('#product-form')[0].reset();
        $('#product-id').val('');
        $('#product-kind').val('BIEN');
        $('#product-tax-treatment').val('GRAVADO');
        $('#product-active').prop('checked', true);
        $('#product-stock-control').prop('checked', false);
        fillUnits('');
        updateStockField();
        $('#product-modal-title').text('Nuevo producto o servicio');
        $('#product-modal-alert').addClass('d-none');
        FacturaSV.showFieldErrors($('#product-form'), {});
        productModal.show();
    }

    $('#new-product').on('click', function () {
        optionsRequest.done(openCreate).fail(function () {
            showError('No fue posible cargar las unidades de medida. Recargue la página.');
        });
    });

    $('#product-stock-control').on('change', updateStockField);

    $('#products-table-body').on('click', '.edit-product', function () {
        var id = $(this).data('id');
        optionsRequest.done(function () {
            $.ajax({
                url: app.data('api'), method: 'GET', dataType: 'json', data: { action: 'get', id: id }
            }).done(function (response) {
                var product = response.product;
                $('#product-form')[0].reset();
                $('#product-id').val(product.id);
                $('#product-code').val(product.codigo);
                $('#product-barcode').val(product.codigo_barras || '');
                $('#product-description').val(product.descripcion);
                $('#product-kind').val(product.tipo);
                fillUnits(product.unidad_medida_id);
                $('#product-price').val(product.precio_unitario);
                $('#product-tax-treatment').val(product.tratamiento_iva);
                $('#product-stock-control').prop('checked', Number(product.control_stock) === 1);
                $('#product-stock').val(product.stock || '');
                updateStockField();
                $('#product-active').prop('checked', Number(product.activo) === 1);
                $('#product-modal-title').text('Editar producto o servicio');
                $('#product-modal-alert').addClass('d-none');
                FacturaSV.showFieldErrors($('#product-form'), {});
                productModal.show();
            }).fail(function (xhr) {
                var response = xhr.responseJSON || {};
                showError(response.message || 'No fue posible cargar el producto o servicio.');
            });
        });
    });

    $('#product-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        if (!this.checkValidity()) {
            this.reportValidity();
            return;
        }
        $('#product-modal-alert').addClass('d-none');
        FacturaSV.showFieldErrors(form, {});
        FacturaSV.setLoading(form, true);
        $.ajax({
            url: app.data('api'), method: 'POST', dataType: 'json',
            data: form.serialize() + '&action=save'
        }).done(function (response) {
            productModal.hide();
            FacturaSV.setLoading(form, false);
            showToast(response.message);
            loadProducts();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            $('#product-modal-alert').text(response.message || 'No fue posible guardar.').removeClass('d-none');
            FacturaSV.showFieldErrors(form, response.errors || {});
            FacturaSV.setLoading(form, false);
        });
    });

    $('#products-table-body').on('click', '.delete-product', function () {
        var id = $(this).data('id');
        if (!window.confirm('¿Desea eliminar este producto o servicio? Si tiene documentos relacionados, deberá desactivarlo.')) {
            return;
        }
        $.ajax({
            url: app.data('api'), method: 'POST', dataType: 'json',
            data: { action: 'delete', id: id, csrf_token: $('meta[name="csrf-token"]').attr('content') }
        }).done(function (response) {
            showToast(response.message);
            if ($('#products-table-body tr').length === 1 && state.page > 1) {
                state.page -= 1;
            }
            loadProducts();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showError(response.message || 'No fue posible eliminar el registro.');
        });
    });

    var liveProductSearch = FacturaSV.debounce(function () {
        state.page = 1;
        loadProducts();
    }, 300);
    $('#product-search').on('input', liveProductSearch);

    $('#product-filters').on('submit', function (event) {
        event.preventDefault();
        state.page = 1;
        loadProducts();
    });

    $('#product-type, #product-tax, #product-status').on('change', function () {
        state.page = 1;
        loadProducts();
    });

    $('#products-page-prev').on('click', function () {
        if (state.page > 1) {
            state.page -= 1;
            loadProducts();
        }
    });

    $('#products-page-next').on('click', function () {
        if (state.page < state.pages) {
            state.page += 1;
            loadProducts();
        }
    });

    loadProducts();
})(jQuery);
