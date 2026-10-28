(function ($) {
    'use strict';

    var app = $('#clients-app');
    var canWrite = Number(app.data('can-write')) === 1;
    var canDelete = Number(app.data('can-delete')) === 1;
    var clientModal = canWrite ? new bootstrap.Modal(document.getElementById('client-modal')) : null;
    var importModal = canWrite ? new bootstrap.Modal(document.getElementById('import-modal')) : null;
    var contributorLabels = {
        GRAN_CONTRIBUYENTE: 'Gran contribuyente',
        SUJETO_EXCLUIDO: 'Sujeto excluido',
        OTRO: 'Otro'
    };
    var state = {
        page: 1,
        pages: 1,
        departments: [],
        municipalities: [],
        request: null
    };

    var optionsRequest = $.ajax({
        url: app.data('api'),
        method: 'GET',
        dataType: 'json',
        data: { action: 'options' }
    }).done(function (response) {
        state.departments = response.departamentos;
        state.municipalities = response.municipios;
        contributorLabels = response.tipos_contribuyente;
        fillDepartments('');
    });

    function showToast(message) {
        $('#app-toast-message').text(message);
        new bootstrap.Toast(document.getElementById('app-toast')).show();
    }

    function showError(message) {
        $('#client-alert').text(message).removeClass('d-none');
    }

    function fillDepartments(selected) {
        var select = $('#client-department').empty();
        select.append($('<option>').val('').text('Seleccione…'));
        $.each(state.departments, function (_, department) {
            select.append($('<option>').val(department.id).text(department.codigo + ' — ' + department.nombre));
        });
        select.val(selected || '');
        fillMunicipalities(selected, '');
    }

    function fillMunicipalities(departmentId, selected) {
        var select = $('#client-municipality').empty();
        select.append($('<option>').val('').text(departmentId ? 'Seleccione…' : 'Seleccione primero un departamento…'));
        $.each(state.municipalities, function (_, municipality) {
            if (String(municipality.departamento_id) === String(departmentId)) {
                select.append($('<option>').val(municipality.id).text(municipality.codigo + ' — ' + municipality.nombre));
            }
        });
        select.val(selected || '');
    }

    function setDocumentMask(type, clear) {
        var input = $('#client-document');
        if (clear) {
            input.val('');
        }
        var withoutDocument = type === 'NINGUNO';
        input.prop('disabled', withoutDocument);
        input.prop('required', !withoutDocument);
        if (withoutDocument) {
            input.removeAttr('data-mask');
            input.removeAttr('maxlength');
            input.attr('placeholder', 'Sin documento');
            input[0].setCustomValidity('');
            return;
        }
        input.attr('data-mask', type === 'DUI' ? 'dui' : 'nit');
        input.attr('maxlength', type === 'DUI' ? 10 : 17);
        input.attr('placeholder', type === 'DUI' ? '00000000-0' : '0000-000000-000-0');
        input.trigger('input');
    }

    function renderRows(items) {
        var body = $('#clients-table-body').empty();
        if (!items.length) {
            body.append($('<tr>').append(
                $('<td>').attr('colspan', 7).addClass('text-center text-muted py-4')
                    .text('No se encontraron clientes con los filtros seleccionados.')
            ));
            return;
        }

        $.each(items, function (_, client) {
            var row = $('<tr>');
            var name = $('<td>');
            name.append($('<strong>').addClass('client-name').text(client.nombre));
            if (client.nombre_comercial) {
                name.append($('<span>').addClass('client-secondary').text(client.nombre_comercial));
            }
            row.append(name);

            var document = $('<td>');
            if (client.tipo_documento === 'NINGUNO') {
                document.append($('<span>').addClass('document-type').text('Sin documento'));
            } else {
                document.append($('<span>').addClass('document-type').text(client.tipo_documento));
                document.append($('<span>').addClass('client-secondary text-nowrap').text(client.numero_documento || ''));
            }
            row.append(document);

            var contact = $('<td>');
            contact.append($('<span>').text(client.email || 'Sin correo'));
            contact.append($('<span>').addClass('client-secondary').text(client.telefono || 'Sin teléfono'));
            row.append(contact);
            row.append($('<td>').text(client.municipio_nombre + ', ' + client.departamento_nombre));
            row.append($('<td>').text(contributorLabels[client.tipo_contribuyente] || client.tipo_contribuyente));
            row.append($('<td>').append(
                $('<span>').addClass(Number(client.activo) === 1 ? 'status-active' : 'status-inactive')
                    .text(Number(client.activo) === 1 ? 'Activo' : 'Inactivo')
            ));

            if (canWrite) {
                var actions = $('<td>').addClass('text-end text-nowrap');
                actions.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-outline-primary me-1 edit-client')
                    .attr('data-id', client.id).attr('aria-label', 'Editar ' + client.nombre).text('Editar'));
                if (canDelete) {
                    actions.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-outline-danger delete-client')
                        .attr('data-id', client.id).attr('aria-label', 'Eliminar ' + client.nombre).text('Eliminar'));
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
        $('#clients-pagination-summary').text('Mostrando ' + start + '–' + end + ' de ' + pagination.total + ' clientes');
        $('#clients-pagination-page').text('Página ' + state.page + ' de ' + state.pages);
        $('#clients-page-prev').prop('disabled', state.page <= 1);
        $('#clients-page-next').prop('disabled', state.page >= state.pages);
    }

    function loadClients() {
        if (state.request) {
            state.request.abort();
        }
        $('#client-alert').addClass('d-none');
        state.request = $.ajax({
            url: app.data('api'),
            method: 'GET',
            dataType: 'json',
            data: {
                action: 'list',
                page: state.page,
                search: $('#client-search').val(),
                tipo_contribuyente: $('#client-contributor').val(),
                status: $('#client-status').val()
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
            showError(response.message || 'No fue posible cargar los clientes.');
        });
    }

    function openCreate() {
        var form = $('#client-form')[0];
        form.reset();
        $('#client-document, #client-phone').each(function () {
            this.setCustomValidity('');
        });
        $('#client-id').val('');
        $('#client-active').prop('checked', true);
        $('#client-contributor-type').val('OTRO');
        fillDepartments('');
        setDocumentMask('NIT', true);
        $('#client-modal-title').text('Nuevo cliente');
        $('#client-modal-alert').addClass('d-none');
        FacturaSV.showFieldErrors($('#client-form'), {});
        clientModal.show();
    }

    $('#new-client').on('click', function () {
        optionsRequest.done(openCreate).fail(function () {
            showError('No fue posible cargar departamentos y municipios. Recargue la página.');
        });
    });

    $('#client-department').on('change', function () {
        fillMunicipalities($(this).val(), '');
    });

    $('#client-document-type').on('change', function () {
        setDocumentMask($(this).val(), true);
    });

    $('#clients-table-body').on('click', '.edit-client', function () {
        var id = $(this).data('id');
        optionsRequest.done(function () {
            $.ajax({
                url: app.data('api'),
                method: 'GET',
                dataType: 'json',
                data: { action: 'get', id: id }
            }).done(function (response) {
                var client = response.client;
                $('#client-id').val(client.id);
                $('#client-document-type').val(client.tipo_documento);
                setDocumentMask(client.tipo_documento, true);
                $('#client-document').val(client.numero_documento).trigger('input');
                $('#client-name').val(client.nombre);
                $('#client-trade-name').val(client.nombre_comercial || '');
                $('#client-activity').val(client.actividad_economica || '');
                $('#client-address').val(client.direccion);
                fillDepartments(client.departamento_id);
                fillMunicipalities(client.departamento_id, client.municipio_id);
                $('#client-phone').val(client.telefono || '').trigger('input');
                $('#client-email').val(client.email || '');
                $('#client-contributor-type').val(client.tipo_contribuyente);
                $('#client-active').prop('checked', Number(client.activo) === 1);
                $('#client-modal-title').text('Editar cliente');
                $('#client-modal-alert').addClass('d-none');
                FacturaSV.showFieldErrors($('#client-form'), {});
                clientModal.show();
            }).fail(function (xhr) {
                var response = xhr.responseJSON || {};
                showError(response.message || 'No fue posible cargar el cliente.');
            });
        });
    });

    $('#client-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        if (!this.checkValidity()) {
            this.reportValidity();
            return;
        }
        $('#client-modal-alert').addClass('d-none');
        FacturaSV.showFieldErrors(form, {});
        FacturaSV.setLoading(form, true);
        $.ajax({
            url: app.data('api'),
            method: 'POST',
            dataType: 'json',
            data: form.serialize() + '&action=save'
        }).done(function (response) {
            clientModal.hide();
            FacturaSV.setLoading(form, false);
            showToast(response.message);
            loadClients();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            $('#client-modal-alert').text(response.message || 'No fue posible guardar el cliente.').removeClass('d-none');
            FacturaSV.showFieldErrors(form, response.errors || {});
            FacturaSV.setLoading(form, false);
        });
    });

    $('#clients-table-body').on('click', '.delete-client', function () {
        var id = $(this).data('id');
        if (!window.confirm('¿Desea eliminar este cliente? Si tiene documentos relacionados, deberá desactivarlo.')) {
            return;
        }
        $.ajax({
            url: app.data('api'),
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'delete', id: id,
                csrf_token: $('meta[name="csrf-token"]').attr('content')
            }
        }).done(function (response) {
            showToast(response.message);
            if ($('#clients-table-body tr').length === 1 && state.page > 1) {
                state.page -= 1;
            }
            loadClients();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showError(response.message || 'No fue posible eliminar el cliente.');
        });
    });

    var liveClientSearch = FacturaSV.debounce(function () {
        state.page = 1;
        loadClients();
    }, 300);
    $('#client-search').on('input', liveClientSearch);

    $('#client-filters').on('submit', function (event) {
        event.preventDefault();
        state.page = 1;
        loadClients();
    });

    $('#client-contributor, #client-status').on('change', function () {
        state.page = 1;
        loadClients();
    });

    $('#clients-page-prev').on('click', function () {
        if (state.page > 1) {
            state.page -= 1;
            loadClients();
        }
    });

    $('#clients-page-next').on('click', function () {
        if (state.page < state.pages) {
            state.page += 1;
            loadClients();
        }
    });

    $('#export-clients').on('click', function () {
        var params = $.param({
            action: 'export',
            search: $('#client-search').val(),
            tipo_contribuyente: $('#client-contributor').val(),
            status: $('#client-status').val()
        });
        window.location.assign(app.data('api') + '?' + params);
    });

    $('#open-import').on('click', function () {
        $('#import-form')[0].reset();
        $('#import-alert, #import-errors').addClass('d-none');
        importModal.show();
    });

    $('#import-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        if (!this.checkValidity()) {
            this.reportValidity();
            return;
        }
        $('#import-alert, #import-errors').addClass('d-none');
        FacturaSV.setLoading(form, true);
        $.ajax({
            url: app.data('api'),
            method: 'POST',
            dataType: 'json',
            data: new FormData(this),
            processData: false,
            contentType: false
        }).done(function (response) {
            importModal.hide();
            FacturaSV.setLoading(form, false);
            showToast(response.message);
            loadClients();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            $('#import-alert').text(response.message || 'No fue posible importar el archivo.').removeClass('d-none');
            var errorBox = $('#import-errors').empty();
            if (response.errores && response.errores.length) {
                var list = $('<ol>');
                $.each(response.errores, function (_, error) {
                    list.append($('<li>').text('Fila ' + error.fila + ': ' + error.mensajes.join(' ')));
                });
                errorBox.append(list);
                if (response.errores_omitidos > 0) {
                    errorBox.append($('<p>').text('Hay ' + response.errores_omitidos + ' errores adicionales no mostrados.'));
                }
                errorBox.removeClass('d-none');
            }
            FacturaSV.setLoading(form, false);
        });
    });

    loadClients();
})(jQuery);
