(function ($) {
    'use strict';
    var app = $('#config-app');
    var state = { data: null, departments: [], municipalities: [] };

    function csrf() { return $('meta[name="csrf-token"]').attr('content'); }
    function showToast(message) { $('#app-toast-message').text(message); new bootstrap.Toast(document.getElementById('app-toast')).show(); }
    function showAlert(message) { $('#config-alert').text(message).removeClass('d-none'); }
    function hideAlert() { $('#config-alert').addClass('d-none'); }
    function post(action, data) {
        return $.ajax({
            url: app.data('api'), method: 'POST', dataType: 'json',
            data: $.extend({ action: action, csrf_token: csrf() }, data || {})
        });
    }
    function firstError(response) {
        var errors = (response || {}).errors || {};
        var values = Object.keys(errors).map(function (key) { return errors[key]; });
        return values.length ? values[0] : (response && response.message) || '';
    }
    function roleLabel(role) { return (state.data && state.data.roles[role]) || role; }

    function renderLogo(url) {
        if (url) {
            $('#logo-image').attr('src', url).removeClass('d-none');
            $('#logo-placeholder').addClass('d-none');
        } else {
            $('#logo-image').addClass('d-none').attr('src', '');
            $('#logo-placeholder').removeClass('d-none');
        }
    }

    function renderCompany(tenant) {
        $('#company-razon').val(tenant.razon_social);
        $('#company-nombre').val(tenant.nombre_comercial);
        $('#company-nit').val(tenant.nit);
        $('#company-nrc').val(tenant.numero_registro_mh);
        $('#company-actividad').val(tenant.codigo_actividad);
        $('#company-telefono').val(tenant.telefono);
        $('#company-email').val(tenant.email);
        $('#company-direccion').val(tenant.direccion);
        renderLogo(tenant.logo_url);
    }

    function fillDepartments(selected) {
        var select = $('#emitter-department').empty();
        $.each(state.departments, function (_, department) {
            select.append($('<option>').val(department.codigo).text(department.codigo + ' — ' + department.nombre));
        });
        select.val(selected || (state.departments[0] ? state.departments[0].codigo : ''));
        fillMunicipalities(select.val(), '');
    }

    function fillMunicipalities(departmentCode, selected) {
        var select = $('#emitter-municipality').empty();
        var department = null;
        $.each(state.departments, function (_, item) {
            if (String(item.codigo) === String(departmentCode)) department = item;
        });
        $.each(state.municipalities, function (_, municipality) {
            if (department && String(municipality.departamento_id) === String(department.id)) {
                select.append($('<option>').val(municipality.codigo).text(municipality.codigo + ' — ' + municipality.nombre));
            }
        });
        select.val(selected || (select.find('option').first().val() || ''));
    }

    function renderEmitter(config) {
        $('#emitter-establecimiento').val(config.establecimiento);
        $('#emitter-distrito').val(config.distrito_emisor);
        $('#emitter-mode').val(config.mh_mode);
        $('#emitter-billing-mode').val(config.modo_facturacion === 'INTERNO'
            ? 'Interno (comprobante no fiscal)'
            : 'Simulación (DTE de prueba)');
        fillDepartments(config.departamento_emisor);
        fillMunicipalities(config.departamento_emisor, config.municipio_emisor);
        var timezones = $('#emitter-timezone').empty();
        $.each(state.data.timezones, function (value, label) {
            timezones.append($('<option>').val(value).text(label));
        });
        timezones.val(config.timezone);
    }

    function renderSequences(sequences) {
        var body = $('#sequences-body').empty();
        if (!sequences.length) {
            body.append($('<tr>').append($('<td>').attr('colspan', 8).addClass('text-center text-muted py-4').text('No hay series configuradas.')));
            return;
        }
        $.each(sequences, function (_, sequence) {
            var row = $('<tr>').attr('data-id', sequence.id).attr('data-type', sequence.tipo_dte);
            var name = $('<td>');
            name.append($('<strong>').text(sequence.tipo_dte + ' · ' + sequence.nombre));
            if (!sequence.disponible) name.append($('<span>').addClass('sequence-pending').text('Pendiente de esquema'));
            row.append(name);
            row.append($('<td>').addClass('text-nowrap').text(sequence.establecimiento));
            row.append($('<td>').append($('<input>').addClass('form-control form-control-sm seq-prefix').attr({ maxlength: 20, value: sequence.prefijo })));
            row.append($('<td>').addClass('text-end').append($('<input>').addClass('form-control form-control-sm seq-initial text-end').attr({ type: 'number', min: 1, step: 1, value: sequence.numero_inicial })));
            row.append($('<td>').addClass('text-end').append($('<input>').addClass('form-control form-control-sm seq-current text-end').attr({ type: 'number', min: 0, step: 1, value: sequence.numero_actual })));
            row.append($('<td>').addClass('text-end seq-next').text(Number(sequence.numero_actual) + 1));
            row.append($('<td>').addClass('text-center').append($('<input>').addClass('form-check-input seq-active').attr({ type: 'checkbox', checked: Number(sequence.activo) === 1 })));
            row.append($('<td>').addClass('text-end').append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-outline-primary sequence-save').text('Guardar')));
            body.append(row);
        });
    }

    function renderUsers(users) {
        var body = $('#users-body').empty();
        $.each(users, function (_, user) {
            var row = $('<tr>').attr('data-id', user.id);
            var name = $('<td>');
            name.append($('<strong>').text(user.nombre));
            if (user.es_actual) name.append($('<span>').addClass('user-current').text('Tú'));
            row.append(name);
            row.append($('<td>').text(user.usuario));
            row.append($('<td>').text(user.email));
            row.append($('<td>').text(roleLabel(user.rol)));
            row.append($('<td>').append($('<span>').addClass('user-status ' + (Number(user.activo) === 1 ? 'user-status-active' : 'user-status-inactive')).text(Number(user.activo) === 1 ? 'Activo' : 'Inactivo')));
            row.append($('<td>').addClass('text-nowrap').text(user.ultimo_acceso || '—'));
            body.append(row);
        });
    }

    function renderMail(mail) {
        var status = mail.simulated ? 'Simulado' : (mail.configured ? 'Configurado' : 'Sin configurar');
        var statusClass = mail.simulated ? 'mail-simulated' : (mail.configured ? 'mail-ready' : 'mail-missing');
        var list = $('#mail-status').empty();
        var add = function (label, value) {
            list.append($('<div>').append($('<dt>').text(label), $('<dd>').text(value || '—')));
        };
        add('Estado', status);
        add('Transporte', mail.transport);
        add('Servidor', mail.host ? mail.host + ':' + mail.port : 'No definido');
        add('Cifrado', mail.encryption);
        add('Remitente', mail.from_name + ' <' + mail.from_email + '>');
        add('Usuario SMTP', mail.user);
        list.find('dd').first().addClass(statusClass);
        if (!$('#mail-test-recipient').val()) {
            var current = (state.data.users || []).filter(function (user) { return user.es_actual; })[0];
            if (current) $('#mail-test-recipient').val(current.email);
        }
    }

    function renderAudit(rows) {
        var labels = { empresa: 'Empresa', emisor: 'Emisor', numeracion: 'Numeración', usuarios: 'Usuarios', correo: 'Correo' };
        var body = $('#audit-body').empty();
        if (!rows.length) {
            body.append($('<tr>').append($('<td>').attr('colspan', 4).addClass('text-center text-muted py-4').text('Todavía no hay cambios registrados.')));
            return;
        }
        $.each(rows, function (_, row) {
            body.append($('<tr>').append(
                $('<td>').text(labels[row.seccion] || row.seccion),
                $('<td>').text(row.detalle),
                $('<td>').text(row.usuario || 'Sistema'),
                $('<td>').addClass('text-nowrap').text(row.fecha || '—')
            ));
        });
    }

    function loadData() {
        hideAlert();
        return $.ajax({ url: app.data('api'), method: 'GET', dataType: 'json', data: { action: 'data' } })
            .done(function (response) {
                state.data = response;
                state.departments = response.departamentos;
                state.municipalities = response.municipios;
                renderCompany(response.tenant);
                renderEmitter(response.config);
                renderSequences(response.sequences);
                renderUsers(response.users);
                renderMail(response.mail);
                renderAudit(response.cambios);
            })
            .fail(function (xhr) { showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar la configuración.'); });
    }

    function submitForm(form, action) {
        var formElement = form[0];
        if (!formElement.checkValidity()) { formElement.reportValidity(); return; }
        hideAlert();
        FacturaSV.setLoading(form, true);
        post(action, form.serialize()).done(function (response) {
            FacturaSV.setLoading(form, false);
            showToast(response.message);
            loadData();
        }).fail(function (xhr) {
            FacturaSV.setLoading(form, false);
            var response = xhr.responseJSON || {};
            FacturaSV.showFieldErrors(form, response.errors || {});
            showAlert(response.message || firstError(response) || 'No fue posible guardar.');
        });
    }

    $('#company-form').on('submit', function (event) {
        event.preventDefault();
        submitForm($(this), 'empresa');
    });
    $('#emitter-form').on('submit', function (event) {
        event.preventDefault();
        submitForm($(this), 'emisor');
    });
    $('#emitter-department').on('change', function () { fillMunicipalities($(this).val(), ''); });

    $('#logo-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        var input = document.getElementById('logo-input');
        if (!input.files || !input.files.length) {
            form.find('[data-error-for="logo"]').text('Seleccione una imagen PNG o JPG.');
            return;
        }
        var data = new FormData();
        data.append('action', 'logo');
        data.append('csrf_token', csrf());
        data.append('logo', input.files[0]);
        FacturaSV.setLoading(form, true);
        $.ajax({ url: app.data('api'), method: 'POST', data: data, processData: false, contentType: false, dataType: 'json' })
            .done(function (response) {
                FacturaSV.setLoading(form, false);
                input.value = '';
                renderLogo(response.logo_url);
                showToast(response.message);
                loadData();
            })
            .fail(function (xhr) {
                FacturaSV.setLoading(form, false);
                var response = xhr.responseJSON || {};
                form.find('[data-error-for="logo"]').text((response.errors || {}).logo || response.message || 'No fue posible subir el logo.');
            });
    });

    $('#remove-logo').on('click', function () {
        if (!window.confirm('¿Desea quitar el logo de la empresa? El PDF mostrará el recuadro de LOGO.')) return;
        post('logo-remove').done(function (response) { showToast(response.message); renderLogo(''); loadData(); })
            .fail(function (xhr) { showAlert((xhr.responseJSON || {}).message || 'No fue posible quitar el logo.'); });
    });

    $('#sequences-body').on('click', '.sequence-save', function () {
        var row = $(this).closest('tr');
        var data = {
            id: row.data('id'),
            prefijo: row.find('.seq-prefix').val(),
            numero_inicial: row.find('.seq-initial').val(),
            numero_actual: row.find('.seq-current').val(),
            activo: row.find('.seq-active').is(':checked') ? '1' : '0'
        };
        post('sequence', data).done(function (response) { showToast(response.message); loadData(); })
            .fail(function (xhr) {
                var response = xhr.responseJSON || {};
                showAlert(firstError(response) || 'No fue posible guardar la serie.');
            });
    });
    $('#sequences-body').on('input', '.seq-current', function () {
        var row = $(this).closest('tr');
        row.find('.seq-next').text(Number($(this).val()) + 1);
    });

    $('#mail-test-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        if (!form[0].checkValidity()) { form[0].reportValidity(); return; }
        hideAlert();
        FacturaSV.setLoading(form, true);
        post('mail-test', { destinatario: $('#mail-test-recipient').val() }).done(function (response) {
            FacturaSV.setLoading(form, false);
            showToast(response.message);
            loadData();
        }).fail(function (xhr) {
            FacturaSV.setLoading(form, false);
            var response = xhr.responseJSON || {};
            if (response.errors) FacturaSV.showFieldErrors(form, response.errors);
            showAlert(response.message || 'No fue posible enviar la prueba.');
        });
    });

    loadData();
})(jQuery);
