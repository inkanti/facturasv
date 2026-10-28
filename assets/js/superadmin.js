(function ($) {
    'use strict';
    var app = $('#superadmin-app');
    var api = app.data('api');
    var companyModal = new bootstrap.Modal(document.getElementById('company-create-modal'));
    var usersModal = new bootstrap.Modal(document.getElementById('company-users-modal'));
    var passwordModal = new bootstrap.Modal(document.getElementById('user-password-modal'));
    var state = { companies: [], tenantId: null, users: [], companyName: '' };

    function csrf() { return $('meta[name="csrf-token"]').attr('content'); }
    function requestError(xhr, fallback) { return (xhr.responseJSON || {}).message || fallback; }
    function showToast(message) {
        $('#app-toast-message').text(message);
        new bootstrap.Toast(document.getElementById('app-toast')).show();
    }
    function showAlert(selector, message) {
        $(selector).text(message).removeClass('d-none');
    }
    function statusBadge(active) {
        return $('<span>').addClass('document-status ' + (Number(active) === 1 ? 'status-procesada' : 'status-rechazada'))
            .text(Number(active) === 1 ? 'Activa' : 'Inactiva');
    }
    function roleLabel(role) {
        return { ADMIN: 'Administrador', VENDEDOR: 'Vendedor', CONTADOR: 'Contador' }[role] || role;
    }

    function renderCompanies(items) {
        state.companies = items || [];
        var body = $('#companies-table-body').empty();
        var active = 0;
        var users = 0;
        $.each(state.companies, function (_, company) {
            active += Number(company.activo) === 1 ? 1 : 0;
            users += Number(company.usuarios) || 0;
            var row = $('<tr>');
            var identity = $('<td>');
            identity.append($('<strong>').text(company.nombre_comercial || company.razon_social));
            identity.append($('<span>').addClass('document-secondary').text(company.razon_social));
            row.append(identity);
            row.append($('<td>').addClass('text-nowrap').text(company.nit));
            var modeSelect = $('<select>').addClass('form-select form-select-sm company-mode')
                .attr({ 'data-id': company.id, 'data-previous': company.modo_facturacion || 'SIMULACION', 'aria-label': 'Modo de facturación' })
                .append($('<option>').val('SIMULACION').text('Simulación'))
                .append($('<option>').val('INTERNO').text('Interno'));
            modeSelect.val(company.modo_facturacion || 'SIMULACION');
            row.append($('<td>').addClass('text-nowrap').append(modeSelect));
            row.append($('<td>').text(company.usuarios));
            row.append($('<td>').text(company.documentos));
            row.append($('<td>').append(statusBadge(company.activo)));
            row.append($('<td>').addClass('text-nowrap').text(String(company.created_at || '').slice(0, 10)));
            var actions = $('<td>').addClass('text-end text-nowrap');
            actions.append($('<button>').attr({ type: 'button', 'data-id': company.id }).addClass('btn btn-sm btn-outline-primary open-users').text('Usuarios'));
            actions.append($('<button>').attr({ type: 'button', 'data-id': company.id, 'data-active': company.activo }).addClass('btn btn-sm ms-1 toggle-company ' + (Number(company.activo) === 1 ? 'btn-outline-danger' : 'btn-outline-success')).text(Number(company.activo) === 1 ? 'Eliminar' : 'Reactivar'));
            row.append(actions);
            body.append(row);
        });
        if (!state.companies.length) {
            body.append($('<tr>').append($('<td>').attr('colspan', 8).addClass('text-center text-muted py-4').text('Todavía no hay empresas.')));
        }
        $('#sa-companies').text(state.companies.length);
        $('#sa-active').text(active);
        $('#sa-users').text(users);
    }

    function loadCompanies() {
        $('#superadmin-alert').addClass('d-none');
        $.ajax({ url: api, method: 'GET', dataType: 'json', data: { action: 'companies' } })
            .done(function (response) { renderCompanies(response.items || []); })
            .fail(function (xhr) { showAlert('#superadmin-alert', requestError(xhr, 'No fue posible cargar las empresas.')); });
    }

    function renderUsers(items) {
        state.users = items || [];
        var body = $('#users-table-body').empty();
        $.each(state.users, function (_, user) {
            var row = $('<tr>');
            row.append($('<td>').text(user.nombre));
            row.append($('<td>').addClass('text-nowrap').text(user.usuario));
            row.append($('<td>').text(user.email));
            row.append($('<td>').text(roleLabel(user.rol)));
            row.append($('<td>').append(statusBadge(user.activo)));
            var actions = $('<td>').addClass('text-end text-nowrap');
            actions.append($('<button>').attr({ type: 'button', 'data-user-id': user.id, 'data-active': user.activo }).addClass('btn btn-sm toggle-user ' + (Number(user.activo) === 1 ? 'btn-outline-danger' : 'btn-outline-success')).text(Number(user.activo) === 1 ? 'Desactivar' : 'Activar'));
            actions.append($('<button>').attr({ type: 'button', 'data-user-id': user.id, 'data-user-label': user.usuario }).addClass('btn btn-sm btn-outline-secondary ms-1 reset-user').text('Contraseña'));
            row.append(actions);
            body.append(row);
        });
        if (!state.users.length) {
            body.append($('<tr>').append($('<td>').attr('colspan', 6).addClass('text-center text-muted py-3').text('La empresa no tiene usuarios.')));
        }
    }

    function loadUsers() {
        $.ajax({ url: api, method: 'GET', dataType: 'json', data: { action: 'users', tenant_id: state.tenantId } })
            .done(function (response) {
                state.companyName = response.tenant.nombre_comercial || response.tenant.razon_social;
                $('#company-users-title').text('Usuarios · ' + state.companyName);
                $('#company-users-subtitle').text(response.tenant.nit);
                renderUsers(response.users || []);
            })
            .fail(function (xhr) { showAlert('#users-alert', requestError(xhr, 'No fue posible cargar los usuarios.')); });
    }

    function openUsers(tenantId) {
        state.tenantId = Number(tenantId);
        $('#users-alert').addClass('d-none');
        $('#user-create-wrap').addClass('d-none');
        $('#company-user-form')[0].reset();
        FacturaSV.showFieldErrors($('#company-user-form'), {});
        $('#user-tenant-id').val(state.tenantId);
        $('#users-table-body').html('<tr><td colspan="6" class="text-center text-muted py-3">Cargando usuarios…</td></tr>');
        usersModal.show();
        loadUsers();
    }

    function openCompanyCreate() {
        var form = $('#company-create-form');
        form[0].reset();
        $('#company-form-alert').addClass('d-none');
        FacturaSV.showFieldErrors(form, {});
        companyModal.show();
    }

    $('#new-company').on('click', openCompanyCreate);
    $('#refresh-companies').on('click', loadCompanies);

    $('#company-create-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        if (!form[0].checkValidity()) { form[0].reportValidity(); return; }
        $('#company-form-alert').addClass('d-none');
        FacturaSV.setLoading(form, true);
        $.ajax({ url: api, method: 'POST', dataType: 'json', data: form.serialize() + '&action=company-create' })
            .done(function (response) {
                FacturaSV.setLoading(form, false);
                companyModal.hide();
                showToast(response.message);
                loadCompanies();
            })
            .fail(function (xhr) {
                FacturaSV.setLoading(form, false);
                var response = xhr.responseJSON || {};
                FacturaSV.showFieldErrors(form, response.errors || {});
                showAlert('#company-form-alert', response.message || 'No fue posible crear la empresa.');
            });
    });

    $('#companies-table-body').on('click', '.open-users', function () { openUsers($(this).data('id')); });
    $('#companies-table-body').on('change', '.company-mode', function () {
        var select = $(this);
        var id = select.data('id');
        var mode = select.val();
        var previous = select.data('previous');
        var label = mode === 'INTERNO'
            ? 'interno (comprobantes no fiscales, sin DTE ni transmisión)'
            : 'simulación (DTE de prueba con sello simulado)';
        if (!window.confirm('¿Cambiar esta empresa al modo ' + label + '? El historial emitido no se convierte.')) {
            select.val(previous);
            return;
        }
        select.prop('disabled', true);
        $.ajax({ url: api, method: 'POST', dataType: 'json', data: { action: 'company-billing-mode', tenant_id: id, modo_facturacion: mode, csrf_token: csrf() } })
            .done(function (response) { showToast(response.message); loadCompanies(); })
            .fail(function (xhr) { showToast(requestError(xhr, 'No fue posible cambiar el modo.')); select.val(previous).prop('disabled', false); });
    });
    $('#companies-table-body').on('click', '.toggle-company', function () {
        var button = $(this);
        var id = button.data('id');
        var active = Number(button.data('active')) === 1;
        var actionLabel = active ? 'eliminar (desactivar)' : 'reactivar';
        if (!window.confirm('¿Desea ' + actionLabel + ' esta empresa? ' + (active ? 'Los datos fiscales se conservan y sus usuarios no podrán iniciar sesión.' : 'Sus usuarios podrán volver a iniciar sesión.'))) return;
        button.prop('disabled', true);
        $.ajax({ url: api, method: 'POST', dataType: 'json', data: { action: 'company-toggle', tenant_id: id, activo: active ? '0' : '1', csrf_token: csrf() } })
            .done(function (response) { showToast(response.message); loadCompanies(); })
            .fail(function (xhr) { showToast(requestError(xhr, 'No fue posible cambiar la empresa.')); button.prop('disabled', false); });
    });

    $('#new-company-user').on('click', function () {
        $('#user-create-wrap').removeClass('d-none');
        $('#users-alert').addClass('d-none');
        $('#company-user-form')[0].reset();
        FacturaSV.showFieldErrors($('#company-user-form'), {});
        $('#user-tenant-id').val(state.tenantId);
        $('#sa-user-nombre').trigger('focus');
    });
    $('#cancel-company-user').on('click', function () { $('#user-create-wrap').addClass('d-none'); });

    $('#company-user-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        if (!form[0].checkValidity()) { form[0].reportValidity(); return; }
        $('#user-form-alert').addClass('d-none');
        $.ajax({ url: api, method: 'POST', dataType: 'json', data: form.serialize() + '&action=user-create' })
            .done(function (response) {
                showToast(response.message);
                $('#user-create-wrap').addClass('d-none');
                loadUsers();
            })
            .fail(function (xhr) {
                var response = xhr.responseJSON || {};
                FacturaSV.showFieldErrors(form, response.errors || {});
                showAlert('#user-form-alert', response.message || 'No fue posible crear el usuario.');
            });
    });

    $('#users-table-body').on('click', '.toggle-user', function () {
        var button = $(this);
        var userId = button.data('user-id');
        var active = Number(button.data('active')) === 1;
        if (!window.confirm(active ? '¿Desactivar este usuario? No podrá iniciar sesión.' : '¿Reactivar este usuario?')) return;
        button.prop('disabled', true);
        $.ajax({ url: api, method: 'POST', dataType: 'json', data: { action: 'user-toggle', tenant_id: state.tenantId, user_id: userId, activo: active ? '0' : '1', csrf_token: csrf() } })
            .done(function (response) { showToast(response.message); loadUsers(); })
            .fail(function (xhr) { showToast(requestError(xhr, 'No fue posible cambiar el usuario.')); button.prop('disabled', false); });
    });

    $('#users-table-body').on('click', '.reset-user', function () {
        var button = $(this);
        $('#user-password-form')[0].reset();
        $('#password-alert').addClass('d-none');
        FacturaSV.showFieldErrors($('#user-password-form'), {});
        $('#password-tenant-id').val(state.tenantId);
        $('#password-user-id').val(button.data('user-id'));
        $('#password-user-label').text('Usuario: ' + button.data('user-label'));
        usersModal.hide();
        passwordModal.show();
    });

    $('#user-password-modal').on('hidden.bs.modal', function () {
        if (state.tenantId) {
            usersModal.show();
            loadUsers();
        }
    });

    $('#user-password-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        if (!form[0].checkValidity()) { form[0].reportValidity(); return; }
        $('#password-alert').addClass('d-none');
        $.ajax({ url: api, method: 'POST', dataType: 'json', data: form.serialize() + '&action=user-reset-password' })
            .done(function (response) {
                passwordModal.hide();
                showToast(response.message);
            })
            .fail(function (xhr) {
                var response = xhr.responseJSON || {};
                FacturaSV.showFieldErrors(form, response.errors || {});
                showAlert('#password-alert', response.message || 'No fue posible cambiar la contraseña.');
            });
    });

    loadCompanies();
})(jQuery);
