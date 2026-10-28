(function ($) {
    'use strict';
    var app = $('#documents-app');
    var canWrite = Number(app.data('can-write')) === 1;
    var isAdmin = Number(app.data('is-admin')) === 1;
    var detailModal = new bootstrap.Modal(document.getElementById('document-detail-modal'));
    var invalidationModal = isAdmin ? new bootstrap.Modal(document.getElementById('invalidation-modal')) : null;
    var state = { page: 1, pages: 1, request: null, currentId: null, currentIsInternal: false };
    var statusLabels = { BORRADOR: 'Borrador', EMITIDA: 'Emitida', PROCESADA: 'Procesada', RECHAZADA: 'Rechazada', INVALIDADA: 'Invalidada' };
    var emailStatusLabels = { ENVIADO: 'Enviado', SIMULADO: 'Simulado', ERROR: 'Error', OMITIDO: 'Omitido' };
    var typeLabels = { '01': 'Factura', '03': 'Crédito Fiscal', '05': 'Nota de Crédito', '06': 'Nota de Débito', '11': 'Factura de Exportación', '14': 'Sujeto Excluido', 'IN': 'Comprobante interno' };

    function money(value) { return '$ ' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function showToast(message) { $('#app-toast-message').text(message); new bootstrap.Toast(document.getElementById('app-toast')).show(); }
    function showError(message) { $('#documents-alert').text(message).removeClass('d-none'); }
    function statusBadge(status) { return $('<span>').addClass('document-status status-' + String(status).toLowerCase()).text(statusLabels[status] || status); }
    function csrf() { return $('meta[name="csrf-token"]').attr('content'); }
    function requestError(xhr, fallback) { return (xhr.responseJSON || {}).message || fallback; }

    function renderRows(items) {
        var body = $('#documents-table-body').empty();
        if (!items.length) { body.append($('<tr>').append($('<td>').attr('colspan', 6).addClass('text-center text-muted py-4').text('No hay documentos con los filtros seleccionados.'))); return; }
        $.each(items, function (_, document) {
            var row = $('<tr>');
            var identity = $('<td>');
            identity.append($('<strong>').text(typeLabels[document.tipo_dte] || document.tipo_dte));
            identity.append($('<span>').addClass('document-secondary').text(document.numero_control || 'Borrador #' + document.id));
            row.append(identity);
            var client = $('<td>');
            client.append($('<span>').text(document.cliente_nombre));
            client.append($('<span>').addClass('document-secondary').text(document.cliente_documento || 'Sin documento'));
            row.append(client);
            row.append($('<td>').addClass('text-nowrap').text(document.fecha_emision));
            row.append($('<td>').addClass('text-nowrap').text(money(document.total_pagar)));
            var statusCell = $('<td>').append(statusBadge(document.estado));
            if (Number(document.tipo_operacion) === 2) {
                statusCell.append($('<span>').addClass('contingency-badge ms-1').text('Contingencia'));
            }
            row.append(statusCell);
            var actions = $('<td>').addClass('text-end text-nowrap');
            actions.append($('<button>').attr({ type: 'button', 'data-id': document.id }).addClass('btn btn-sm btn-outline-primary view-document').text('Detalle'));
            if (document.numero_control) {
                actions.append($('<a>').addClass('btn btn-sm btn-outline-secondary ms-1').attr({ href: app.data('pdf-url') + '&id=' + document.id, target: '_blank', rel: 'noopener' }).text('PDF'));
            }
            if (document.numero_control && (document.estado === 'PROCESADA' || document.estado === 'INVALIDADA')) {
                actions.append($('<a>').addClass('btn btn-sm btn-outline-secondary ms-1').attr({ href: app.data('ticket-url') + '?id=' + document.id, target: '_blank', rel: 'noopener' }).text('Ticket'));
            }
            if (canWrite && document.estado === 'BORRADOR') {
                actions.append($('<a>').addClass('btn btn-sm btn-outline-secondary ms-1').attr('href', app.data('edit-url') + '?id=' + document.id).text('Editar'));
            }
            row.append(actions); body.append(row);
        });
    }
    function renderPagination(pagination) {
        state.page = Number(pagination.page); state.pages = Number(pagination.pages);
        var start = pagination.total === 0 ? 0 : ((state.page - 1) * pagination.per_page) + 1;
        var end = Math.min(state.page * pagination.per_page, pagination.total);
        $('#documents-pagination-summary').text('Mostrando ' + start + '–' + end + ' de ' + pagination.total + ' registros');
        $('#documents-pagination-page').text('Página ' + state.page + ' de ' + state.pages);
        $('#documents-page-prev').prop('disabled', state.page <= 1);
        $('#documents-page-next').prop('disabled', state.page >= state.pages);
    }
    function loadDocuments() {
        if (state.request) state.request.abort();
        $('#documents-alert').addClass('d-none');
        state.request = $.ajax({ url: app.data('api'), method: 'GET', dataType: 'json', data: {
            action: 'list', page: state.page, fecha_desde: $('#filter-from').val(), fecha_hasta: $('#filter-to').val(),
            tipo_dte: $('#filter-type').val(), estado: $('#filter-status').val(), cliente_id: $('#filter-client').val()
        }}).done(function (response) { renderRows(response.items); renderPagination(response.pagination); })
            .fail(function (xhr, textStatus) { if (textStatus !== 'abort') { renderRows([]); showError(requestError(xhr, 'No fue posible cargar los documentos.')); } });
    }
    function detailBlock(label, value) { return $('<div>').append($('<dt>').text(label), $('<dd>').text(value || '—')); }
    function jsonPanel(title, value) {
        var section = $('<section>').addClass('document-json-section');
        section.append($('<h3>').text(title));
        section.append($('<pre>').text(value ? JSON.stringify(value, null, 2) : 'Aún no disponible.'));
        return section;
    }
    function renderDetail(response) {
        var document = response.document;
        state.currentId = document.id;
        $('#document-detail-title').text(typeLabels[document.tipo_dte] + ' · ' + (statusLabels[document.estado] || document.estado));
        $('#document-detail-control').text(document.numero_control || 'Borrador #' + document.id);
        var body = $('#document-detail-body').empty();
        var isInternal = document.tipo_dte === 'IN';
        state.currentIsInternal = isInternal;
        var info = $('<dl>').addClass('document-detail-grid');
        info.append(
            detailBlock('Cliente', document.cliente_nombre),
            detailBlock('Documento receptor', document.cliente_documento),
            detailBlock('Correo del cliente', document.cliente_email),
            detailBlock('Fecha y hora', document.fecha_emision + (document.hora_emision ? ' ' + document.hora_emision : '')),
            detailBlock('Condición', document.condicion_nombre + (document.dias_credito ? ' · ' + document.dias_credito + ' días' : '')),
            detailBlock('Forma de pago', document.pago_nombre),
            detailBlock('Tipo de transmisión', isInternal ? 'No aplica · control interno' : (Number(document.tipo_operacion) === 2 ? 'Contingencia' : 'Normal'))
        );
        if (!isInternal) {
            info.append(detailBlock('Código de generación', document.codigo_generacion), detailBlock('Sello de recepción', document.sello_recepcion));
        }
        info.append(detailBlock('Total a pagar', money(document.total_pagar)));
        body.append(info);
        if (isInternal) {
            body.append($('<div>').addClass('alert alert-info').text('Comprobante interno: no genera DTE, sello ni código de generación, y no se transmite al Ministerio de Hacienda. No tiene validez fiscal.'));
        }
        var table = $('<table>').addClass('table detail-items-table');
        table.append($('<thead>').append($('<tr>').append($('<th>').text('#'), $('<th>').text('Descripción'), $('<th>').text('Cantidad'), $('<th>').text('Precio'), $('<th>').text('Descuento'), $('<th>').text('IVA'), $('<th>').text('Gravada'), $('<th>').text('Exenta'), $('<th>').text('No sujeta'))));
        var itemBody = $('<tbody>');
        $.each(response.items, function (_, item) { itemBody.append($('<tr>').append($('<td>').text(item.num_item), $('<td>').text(item.descripcion), $('<td>').text(item.cantidad), $('<td>').text(money(item.precio_unitario)), $('<td>').text(money(item.descuento)), $('<td>').text(money(item.iva_item)), $('<td>').text(money(item.venta_gravada)), $('<td>').text(money(item.venta_exenta)), $('<td>').text(money(item.venta_no_suj)))); });
        body.append($('<div>').addClass('table-responsive detail-items-wrap').append(table.append(itemBody)));
        var totals = $('<dl>').addClass('detail-totals');
        totals.append(detailBlock('Gravada', money(document.total_gravada)), detailBlock('Exenta', money(document.total_exenta)), detailBlock('No sujeta', money(document.total_no_suj)), detailBlock('Descuentos', money(document.total_descuento)), detailBlock('IVA', money(document.total_iva)), detailBlock('IVA retenido', money(document.iva_rete1)), detailBlock('Total a pagar', money(document.total_pagar)));
        body.append(totals);
        if (document.motivo_invalidacion) body.append($('<div>').addClass('alert alert-warning').text('Motivo de invalidación: ' + document.motivo_invalidacion));
        if (Number(document.tipo_operacion) === 2) {
            var contingencyMessage = 'Documento emitido en contingencia (tipo ' + (document.tipo_contingencia || '—') + '). ';
            contingencyMessage += document.contingencia_evento_id
                ? 'Está incluido en el evento #' + document.contingencia_evento_id + '.'
                : 'Aún no está incluido en un evento de contingencia.';
            if (document.motivo_contingencia) contingencyMessage += ' Motivo: ' + document.motivo_contingencia;
            body.append($('<div>').addClass('alert alert-warning').text(contingencyMessage));
        }
        if (isInternal) {
            body.append($('<section>').addClass('document-email-section')
                .append($('<h3>').text('Envío por correo'))
                .append($('<p>').addClass('text-muted').text('El modo interno no prepara correo fiscal automático. Puede imprimir el comprobante o su ticket.')));
        } else {
            var emailSection = $('<section>').addClass('document-email-section').append($('<h3>').text('Envío por correo'));
            if (document.cliente_email) {
                emailSection.append($('<p>').addClass('text-muted').text('Destinatario: ' + document.cliente_email));
            } else {
                emailSection.append($('<p>').addClass('text-muted').text('El cliente no tiene correo electrónico registrado.'));
            }
            var emailLogs = response.correos || [];
            if (!emailLogs.length) {
                emailSection.append($('<p>').addClass('text-muted').text('Todavía no se ha enviado este documento por correo.'));
            } else {
                var emailTable = $('<table>').addClass('table detail-email-table');
                emailTable.append($('<thead>').append($('<tr>').append($('<th>').text('Fecha'), $('<th>').text('Destinatario'), $('<th>').text('Transporte'), $('<th>').text('Estado'), $('<th>').text('Detalle'))));
                var emailBody = $('<tbody>');
                $.each(emailLogs, function (_, item) {
                    emailBody.append($('<tr>').append(
                        $('<td>').addClass('text-nowrap').text(item.created_at),
                        $('<td>').text(item.destinatario),
                        $('<td>').text(item.transporte),
                        $('<td>').append($('<span>').addClass('email-status email-status-' + String(item.estado).toLowerCase()).text(emailStatusLabels[item.estado] || item.estado)),
                        $('<td>').addClass('email-error').text(item.error || (item.adjuntos || '—'))
                    ));
                });
                emailSection.append($('<div>').addClass('table-responsive').append(emailTable.append(emailBody)));
            }
            body.append(emailSection);
            body.append(jsonPanel('JSON del DTE', document.dte));
            var history = $('<section>').addClass('transmission-history').append($('<h3>').text('Historial de firma y transmisión'));
            if (!response.logs.length) history.append($('<p>').addClass('text-muted').text('Todavía no hay intentos registrados.'));
            $.each(response.logs, function (_, log) {
                var entry = $('<details>').addClass('transmission-entry');
                entry.append($('<summary>').text(log.accion + ' · ' + log.estado + ' · ' + log.created_at + (log.usuario ? ' · ' + log.usuario : '')));
                entry.append(jsonPanel('Solicitud', log.request), jsonPanel('Respuesta', log.response)); history.append(entry);
            });
            body.append(history);
        }
        if (document.invalidacion) body.append(jsonPanel('Evento de invalidación', document.invalidacion));
        var actions = $('#document-detail-actions').empty();
        actions.append($('<button>').attr({ type: 'button', 'data-bs-dismiss': 'modal' }).addClass('btn btn-outline-secondary').text('Cerrar'));
        if (document.codigo_generacion) actions.append($('<button>').attr({ type: 'button', 'data-id': document.id }).addClass('btn btn-outline-primary consult-document').text('Consultar estado'));
        if (document.dte) actions.append($('<a>').addClass('btn btn-outline-primary').attr('href', app.data('download-url') + '&id=' + document.id).text('Descargar JSON'));
        if (document.numero_control) {
            actions.append($('<a>').addClass('btn btn-outline-primary').attr({ href: app.data('pdf-url') + '&id=' + document.id, target: '_blank', rel: 'noopener' }).text('Ver PDF'));
            actions.append($('<a>').addClass('btn btn-outline-secondary').attr('href', app.data('pdf-url') + '&download=1&id=' + document.id).text('Descargar PDF'));
        }
        if (document.numero_control && (document.estado === 'PROCESADA' || document.estado === 'INVALIDADA')) {
            actions.append($('<a>').addClass('btn btn-outline-primary').attr({ href: app.data('ticket-url') + '?id=' + document.id, target: '_blank', rel: 'noopener' }).text('Imprimir ticket'));
        }
        if (Number(document.tipo_operacion) === 2) {
            actions.append($('<a>').addClass('btn btn-outline-warning')
                .attr('href', app.data('contingency-url') + (document.contingencia_evento_id ? '?evento=' + document.contingencia_evento_id : ''))
                .text(document.contingencia_evento_id ? 'Ver evento' : 'Ir a contingencia'));
        }
        if (canWrite && !isInternal && document.cliente_email && document.numero_control && (document.estado === 'PROCESADA' || document.estado === 'INVALIDADA')) {
            var alreadySent = (response.correos || []).some(function (item) { return item.estado === 'ENVIADO' || item.estado === 'SIMULADO'; });
            actions.append($('<button>').attr({ type: 'button', 'data-id': document.id }).addClass('btn btn-outline-success email-document').text(alreadySent ? 'Reenviar por correo' : 'Enviar por correo'));
        }
        if (canWrite && document.estado === 'BORRADOR') {
            actions.append($('<a>').addClass('btn btn-primary').attr('href', app.data('edit-url') + '?id=' + document.id).text('Editar borrador'));
            actions.append($('<button>').attr({ type: 'button', 'data-id': document.id }).addClass('btn btn-outline-danger delete-document').text('Eliminar'));
        }
        if (canWrite && (document.estado === 'EMITIDA' || document.estado === 'RECHAZADA') && Number(document.tipo_operacion) !== 2) actions.append($('<button>').attr({ type: 'button', 'data-id': document.id }).addClass('btn btn-primary transmit-document').text(document.estado === 'RECHAZADA' ? 'Reintentar transmisión' : 'Transmitir'));
        if (isAdmin && document.estado === 'PROCESADA') actions.append($('<button>').attr({ type: 'button', 'data-id': document.id }).addClass('btn btn-danger invalidate-document').text(isInternal ? 'Anular comprobante' : 'Invalidar'));
    }
    function openDetail(id) {
        state.currentId = id; detailModal.show();
        $('#document-detail-body').html('<p class="text-muted">Cargando detalle…</p>');
        $.ajax({ url: app.data('api'), method: 'GET', dataType: 'json', data: { action: 'get', id: id } }).done(renderDetail)
            .fail(function (xhr) { $('#document-detail-body').append($('<div>').addClass('alert alert-danger').text(requestError(xhr, 'No fue posible cargar el detalle.'))); });
    }
    function mhAction(action, id, extra) {
        var data = $.extend({ action: action, id: id, csrf_token: csrf() }, extra || {});
        return $.ajax({ url: app.data('mh-api'), method: 'POST', dataType: 'json', data: data });
    }
    function transmit(id, button) {
        button.prop('disabled', true).text('Transmitiendo…');
        mhAction('transmit', id).done(function (response) { showToast(response.message); detailModal.hide(); loadDocuments(); openDetail(id); })
            .fail(function (xhr) { showError(requestError(xhr, 'No fue posible transmitir.')); button.prop('disabled', false).text('Reintentar transmisión'); loadDocuments(); });
    }
    function emailDocument(id, button) {
        button.prop('disabled', true).text('Enviando…');
        $.ajax({ url: app.data('api'), method: 'POST', dataType: 'json', data: { action: 'email', id: id, csrf_token: csrf() } })
            .done(function (response) { showToast(response.message); openDetail(id); })
            .fail(function (xhr) { showToast(requestError(xhr, 'No fue posible enviar el correo.')); button.prop('disabled', false).text('Enviar por correo'); });
    }

    $.ajax({ url: app.data('api'), method: 'GET', dataType: 'json', data: { action: 'options' } }).done(function (response) {
        var select = $('#filter-client');
        $.each(response.clientes, function (_, client) { select.append($('<option>').val(client.id).text(client.nombre)); });
    });
    $('#document-filters').on('submit', function (event) { event.preventDefault(); state.page = 1; loadDocuments(); });
    $('#clear-document-filters').on('click', function () { $('#document-filters')[0].reset(); state.page = 1; loadDocuments(); });
    $('#documents-page-prev').on('click', function () { if (state.page > 1) { state.page--; loadDocuments(); } });
    $('#documents-page-next').on('click', function () { if (state.page < state.pages) { state.page++; loadDocuments(); } });
    $('#documents-table-body').on('click', '.view-document', function () { openDetail($(this).data('id')); });
    $('#document-detail-actions').on('click', '.transmit-document', function () { transmit($(this).data('id'), $(this)); });
    $('#document-detail-actions').on('click', '.email-document', function () { emailDocument($(this).data('id'), $(this)); });
    $('#document-detail-actions').on('click', '.consult-document', function () {
        var button = $(this).prop('disabled', true);
        mhAction('consult', $(this).data('id')).done(function (response) { showToast(response.message); openDetail(state.currentId); })
            .fail(function (xhr) { showError(requestError(xhr, 'No fue posible consultar.')); }).always(function () { button.prop('disabled', false); });
    });
    $('#document-detail-actions').on('click', '.delete-document', function () {
        var id = $(this).data('id');
        if (!window.confirm('¿Desea eliminar este borrador? Esta acción no se puede deshacer.')) return;
        $.ajax({ url: app.data('api'), method: 'POST', dataType: 'json', data: { action: 'delete', id: id, csrf_token: csrf() } }).done(function (response) { detailModal.hide(); showToast(response.message); loadDocuments(); })
            .fail(function (xhr) { showError(requestError(xhr, 'No fue posible eliminar el borrador.')); });
    });
    $('#document-detail-actions').on('click', '.invalidate-document', function () {
        var id = $(this).data('id'); detailModal.hide();
        var isInternal = state.currentIsInternal === true;
        $('#invalidation-form')[0].reset(); $('#invalidation-id').val(id);
        $('#invalidation-title').text(isInternal ? 'Anular comprobante interno' : 'Invalidar documento');
        $('#invalidation-note').text(isInternal
            ? 'La anulación no elimina el comprobante: revierte el inventario y genera el contra-asiento contable. No se envía ningún evento al MH.'
            : 'La invalidación no elimina el documento; registra un evento de invalidación simulado.');
        $('#invalidation-type-group, #invalidation-responsible-group').toggleClass('d-none', isInternal);
        $('#invalidation-type, #responsible-document-type, #responsible-document').prop('required', !isInternal);
        $('#invalidation-alert').addClass('d-none'); FacturaSV.showFieldErrors($('#invalidation-form'), {}); invalidationModal.show();
    });
    if (isAdmin) {
        $('#invalidation-form').on('submit', function (event) {
            event.preventDefault(); var form = $(this);
            if (!this.checkValidity()) { this.reportValidity(); return; }
            FacturaSV.setLoading(form, true); $('#invalidation-alert').addClass('d-none');
            $.ajax({ url: app.data('mh-api'), method: 'POST', dataType: 'json', data: form.serialize() + '&action=invalidate' }).done(function (response) {
                invalidationModal.hide(); FacturaSV.setLoading(form, false); showToast(response.message); loadDocuments(); openDetail($('#invalidation-id').val());
            }).fail(function (xhr) {
                var response = xhr.responseJSON || {}; FacturaSV.setLoading(form, false); FacturaSV.showFieldErrors(form, response.errors || {});
                $('#invalidation-alert').text(response.message || 'No fue posible invalidar.').removeClass('d-none');
            });
        });
        $('#responsible-document-type').on('change', function () { $('#responsible-document').attr('placeholder', $(this).val() === '36' ? '0000-000000-000-0' : '00000000-0'); });
    }
    loadDocuments();
    if (app.data('open-id')) openDetail(app.data('open-id'));
})(jQuery);
