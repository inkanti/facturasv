(function ($) {
    'use strict';

    var app = $('#catalog-app');
    var canEdit = Number(app.data('can-edit')) === 1;
    var modal = canEdit ? new bootstrap.Modal(document.getElementById('catalog-modal')) : null;
    var state = {
        resource: 'tipos-documento',
        page: 1,
        pages: 1,
        municipality: false,
        departments: [],
        request: null
    };

    function showToast(message) {
        $('#app-toast-message').text(message);
        new bootstrap.Toast(document.getElementById('app-toast')).show();
    }

    function showError(message) {
        $('#catalog-alert').text(message).removeClass('d-none');
    }

    function departmentOptions(includeAll) {
        var options = includeAll ? [{ id: '', nombre: 'Todos' }] : [{ id: '', nombre: 'Seleccione…' }];
        return options.concat(state.departments);
    }

    function fillDepartmentSelect(select, includeAll, selected) {
        select.empty();
        $.each(departmentOptions(includeAll), function (_, department) {
            var option = $('<option>').val(department.id).text(department.nombre);
            if (department.codigo) {
                option.text(department.codigo + ' — ' + department.nombre);
            }
            select.append(option);
        });
        select.val(selected || '');
    }

    function renderHead() {
        var head = $('#catalog-table-head').empty();
        head.append($('<th>').text('Código'), $('<th>').text('Nombre'));
        if (state.municipality) {
            head.append($('<th>').text('Departamento'));
        }
        head.append($('<th>').text('Orden'), $('<th>').text('Estado'));
        if (canEdit) {
            head.append($('<th>').addClass('text-end').text('Acciones'));
        }
    }

    function renderRows(items) {
        var body = $('#catalog-table-body').empty();
        if (!items.length) {
            body.append($('<tr>').append(
                $('<td>').attr('colspan', 6).addClass('text-center text-muted py-4')
                    .text('No se encontraron registros con los filtros seleccionados.')
            ));
            return;
        }

        $.each(items, function (_, item) {
            var row = $('<tr>');
            row.append($('<td>').append($('<span>').addClass('code-badge').text(item.codigo)));
            row.append($('<td>').text(item.nombre));
            if (state.municipality) {
                row.append($('<td>').text(item.departamento_nombre));
            }
            row.append($('<td>').text(item.orden));
            row.append($('<td>').append(
                $('<span>').addClass(Number(item.activo) === 1 ? 'status-active' : 'status-inactive')
                    .text(Number(item.activo) === 1 ? 'Activo' : 'Inactivo')
            ));

            if (canEdit) {
                var actions = $('<td>').addClass('text-end text-nowrap');
                actions.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-outline-primary me-1 edit-item')
                    .attr('data-id', item.id).attr('aria-label', 'Editar ' + item.nombre).text('Editar'));
                actions.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-outline-danger delete-item')
                    .attr('data-id', item.id).attr('aria-label', 'Eliminar ' + item.nombre).text('Eliminar'));
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
        $('#pagination-summary').text('Mostrando ' + start + '–' + end + ' de ' + pagination.total + ' registros');
        $('#pagination-page').text('Página ' + state.page + ' de ' + state.pages);
        $('#page-prev').prop('disabled', state.page <= 1);
        $('#page-next').prop('disabled', state.page >= state.pages);
    }

    function loadCatalog() {
        if (state.request) {
            state.request.abort();
        }
        $('#catalog-alert').addClass('d-none');
        $('#catalog-table-body').html($('<tr>').append(
            $('<td>').attr('colspan', 6).addClass('text-center text-muted py-4').text('Cargando registros…')
        ));

        state.request = $.ajax({
            url: app.data('api'),
            method: 'GET',
            dataType: 'json',
            data: {
                action: 'list',
                resource: state.resource,
                page: state.page,
                search: $('#catalog-search').val(),
                status: $('#catalog-status').val(),
                departamento_id: $('#department-filter').val() || ''
            }
        }).done(function (response) {
            state.municipality = response.catalog.municipality;
            state.departments = response.departamentos || [];
            $('#catalog-title').text(response.catalog.label);
            $('#catalog-code').attr('maxlength', response.catalog.code_length);
            $('#code-help').text('Debe contener exactamente ' + response.catalog.code_length + ' dígito(s).');
            $('#department-filter-group, #modal-department-group').toggleClass('d-none', !state.municipality);
            $('#modal-department').prop('required', state.municipality);
            if (state.municipality) {
                fillDepartmentSelect($('#department-filter'), true, $('#department-filter').val());
                fillDepartmentSelect($('#modal-department'), false, $('#modal-department').val());
            }
            renderHead();
            renderRows(response.items);
            renderPagination(response.pagination);
        }).fail(function (xhr, textStatus) {
            if (textStatus === 'abort') {
                return;
            }
            var response = xhr.responseJSON || {};
            renderRows([]);
            showError(response.message || 'No fue posible cargar el catálogo. Inténtelo nuevamente.');
        });
    }

    function openCreateModal() {
        var form = $('#catalog-form')[0];
        form.reset();
        $('#catalog-id').val('');
        $('#catalog-order').val(0);
        $('#catalog-active').prop('checked', true);
        $('#modal-department').val('');
        $('#catalog-modal-title').text('Agregar registro');
        $('#modal-alert').addClass('d-none');
        FacturaSV.showFieldErrors($('#catalog-form'), {});
        modal.show();
    }

    $('.catalog-option').on('click', function () {
        $('.catalog-option').removeClass('active');
        $(this).addClass('active');
        state.resource = $(this).data('resource');
        state.page = 1;
        state.departments = [];
        $('#catalog-search').val('');
        $('#catalog-status, #department-filter').val('');
        loadCatalog();
    });

    var liveCatalogSearch = FacturaSV.debounce(function () {
        state.page = 1;
        loadCatalog();
    }, 300);
    $('#catalog-search').on('input', liveCatalogSearch);

    $('#catalog-filters').on('submit', function (event) {
        event.preventDefault();
        state.page = 1;
        loadCatalog();
    });

    $('#catalog-status, #department-filter').on('change', function () {
        state.page = 1;
        loadCatalog();
    });

    $('#page-prev').on('click', function () {
        if (state.page > 1) {
            state.page -= 1;
            loadCatalog();
        }
    });

    $('#page-next').on('click', function () {
        if (state.page < state.pages) {
            state.page += 1;
            loadCatalog();
        }
    });

    $('#new-catalog-item').on('click', openCreateModal);

    $('#catalog-table-body').on('click', '.edit-item', function () {
        var id = $(this).data('id');
        $.ajax({
            url: app.data('api'),
            method: 'GET',
            dataType: 'json',
            data: { action: 'get', resource: state.resource, id: id }
        }).done(function (response) {
            var item = response.item;
            $('#catalog-id').val(item.id);
            $('#catalog-code').val(item.codigo);
            $('#catalog-name').val(item.nombre);
            $('#catalog-order').val(item.orden);
            $('#catalog-active').prop('checked', Number(item.activo) === 1);
            $('#modal-department').val(item.departamento_id || '');
            $('#catalog-modal-title').text('Editar registro');
            $('#modal-alert').addClass('d-none');
            FacturaSV.showFieldErrors($('#catalog-form'), {});
            modal.show();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showError(response.message || 'No fue posible cargar el registro.');
        });
    });

    $('#catalog-code').on('input', function () {
        this.value = this.value.replace(/\D/g, '');
    });

    $('#catalog-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        if (!this.checkValidity()) {
            this.reportValidity();
            return;
        }

        $('#modal-alert').addClass('d-none');
        FacturaSV.showFieldErrors(form, {});
        FacturaSV.setLoading(form, true);
        $.ajax({
            url: app.data('api') + '?resource=' + encodeURIComponent(state.resource),
            method: 'POST',
            dataType: 'json',
            data: form.serialize() + '&action=save'
        }).done(function (response) {
            modal.hide();
            FacturaSV.setLoading(form, false);
            showToast(response.message);
            loadCatalog();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            $('#modal-alert').text(response.message || 'No fue posible guardar el registro.').removeClass('d-none');
            FacturaSV.showFieldErrors(form, response.errors || {});
            FacturaSV.setLoading(form, false);
        });
    });

    $('#catalog-table-body').on('click', '.delete-item', function () {
        var id = $(this).data('id');
        if (!window.confirm('¿Desea eliminar este registro? Si ya está relacionado con otros datos, deberá desactivarlo.')) {
            return;
        }

        $.ajax({
            url: app.data('api') + '?resource=' + encodeURIComponent(state.resource),
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'delete',
                id: id,
                csrf_token: $('meta[name="csrf-token"]').attr('content')
            }
        }).done(function (response) {
            showToast(response.message);
            if ($('#catalog-table-body tr').length === 1 && state.page > 1) {
                state.page -= 1;
            }
            loadCatalog();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showError(response.message || 'No fue posible eliminar el registro.');
        });
    });

    loadCatalog();
})(jQuery);
