(function ($) {
    'use strict';

    var app = $('#contingency-app');
    var api = app.data('api');
    var isAdmin = Number(app.data('is-admin')) === 1;
    var createModal = isAdmin ? new bootstrap.Modal(document.getElementById('contingency-create-modal')) : null;
    var detailModal = new bootstrap.Modal(document.getElementById('contingency-detail-modal'));
    var typeLabels = {};
    try { typeLabels = JSON.parse(app.attr('data-types') || '{}'); } catch (error) { typeLabels = {}; }
    var state = { currentId: null, pending: [], busy: false, request: null, pendingTimer: null };
    var eventStatusLabels = { BORRADOR: 'Borrador', PROCESADO: 'Procesado', RECHAZADO: 'Rechazado' };
    var documentStatusLabels = { BORRADOR: 'Borrador', EMITIDA: 'Emitida', PROCESADA: 'Procesada', RECHAZADA: 'Rechazada', INVALIDADA: 'Invalidada' };
    var documentTypeLabels = { '01': 'Factura', '03': 'Crédito Fiscal' };

    function money(value) {
        return '$ ' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function showToast(message) {
        $('#app-toast-message').text(message);
        new bootstrap.Toast(document.getElementById('app-toast')).show();
    }
    function showListError(message) { $('#contingency-list-alert').text(message).removeClass('d-none'); }
    function showFormError(message) { $('#contingency-form-alert').text(message).removeClass('d-none'); }
    function csrf() { return $('meta[name="csrf-token"]').attr('content'); }
    function requestError(xhr, fallback) { return (xhr.responseJSON || {}).message || fallback; }
    function shortCode(code) { return code ? String(code).slice(0, 8) + '…' : '—'; }
    function timePart(value) { return String(value || '').slice(0, 5); }
    function typeLabel(value) { return typeLabels[String(value)] || ('Tipo ' + value); }
    function docTypeLabel(value) { return documentTypeLabels[value] || value; }

    function eventStatusBadge(status) {
        return $('<span>').addClass('document-status status-' + String(status).toLowerCase()).text(eventStatusLabels[status] || status);
    }
    function documentStatusBadge(status) {
        return $('<span>').addClass('document-status status-' + String(status).toLowerCase()).text(documentStatusLabels[status] || status);
    }
    function detailBlock(label, value) {
        return $('<div>').append($('<dt>').text(label), $('<dd>').text(value === null || value === undefined || value === '' ? '—' : value));
    }
    function jsonPanel(title, value) {
        var section = $('<section>').addClass('document-json-section');
        section.append($('<h3>').text(title));
        section.append($('<pre>').text(value ? JSON.stringify(value, null, 2) : 'No disponible.'));
        return section;
    }
    function periodText(row) {
        return row.f_inicio + ' ' + timePart(row.h_inicio) + ' – ' + row.f_fin + ' ' + timePart(row.h_fin);
    }

    function renderEvents(items) {
        var body = $('#contingency-table-body').empty();
        if (!items.length) {
            body.append($('<tr>').append($('<td>').attr('colspan', 7).addClass('text-center text-muted py-4').text('Todavía no hay eventos de contingencia.')));
            return;
        }
        $.each(items, function (_, event) {
            var row = $('<tr>');
            var identity = $('<td>');
            identity.append($('<strong>').text('Evento #' + event.id));
            identity.append($('<span>').addClass('document-secondary').text(shortCode(event.codigo_generacion)));
            row.append(identity);
            row.append($('<td>').addClass('text-nowrap').text(periodText(event)));
            row.append($('<td>').text(typeLabel(event.tipo_contingencia)));
            row.append($('<td>').text(event.documentos + (Number(event.documentos) === 1 ? ' documento' : ' documentos')));
            row.append($('<td>').append(eventStatusBadge(event.estado)));
            row.append($('<td>').addClass('text-nowrap').text(event.sello_recepcion || '—'));
            var actions = $('<td>').addClass('text-end text-nowrap');
            actions.append($('<button>').attr({ type: 'button', 'data-id': event.id }).addClass('btn btn-sm btn-outline-primary view-contingency').text('Detalle'));
            actions.append($('<a>').addClass('btn btn-sm btn-outline-secondary ms-1').attr({ href: api + '?action=download&id=' + event.id, target: '_blank', rel: 'noopener' }).text('JSON'));
            if (isAdmin && event.estado === 'RECHAZADO') {
                actions.append($('<button>').attr({ type: 'button', 'data-id': event.id }).addClass('btn btn-sm btn-outline-warning ms-1 retry-contingency').text('Reintentar'));
            }
            row.append(actions);
            body.append(row);
        });
    }

    function loadEvents() {
        if (state.request) state.request.abort();
        $('#contingency-list-alert').addClass('d-none');
        state.request = $.ajax({ url: api, method: 'GET', dataType: 'json', data: { action: 'list' } })
            .done(function (response) { renderEvents(response.items || []); })
            .fail(function (xhr, textStatus) {
                if (textStatus === 'abort') return;
                renderEvents([]);
                showListError(requestError(xhr, 'No fue posible cargar los eventos de contingencia.'));
            });
    }

    function updateReasonRequired() {
        var other = $('#cont-type').val() === '5';
        $('#cont-reason').prop('required', other);
        $('#cont-reason-help').toggleClass('fw-semibold', other);
    }

    function selectedPending() {
        var type = String($('#cont-type').val() || '');
        return state.pending.filter(function (document) {
            return String(document.tipo_contingencia) === type;
        });
    }

    function renderPending() {
        var pending = selectedPending();
        var body = $('#pending-table-body').empty();
        var summary = $('#pending-summary');
        var alert = $('#pending-alert').addClass('d-none');
        if (!pending.length) {
            body.append($('<tr>').append($('<td>').attr('colspan', 6).addClass('text-center text-muted py-3').text('No hay documentos de este tipo de contingencia dentro del periodo.')));
            summary.text(state.pending.length
                ? 'No hay documentos de tipo ' + typeLabel($('#cont-type').val()) + '. Hay ' + state.pending.length + ' documento(s) de otros tipos; cambie el tipo o ajuste el periodo.'
                : 'No hay documentos pendientes en el periodo.');
        } else {
            $.each(pending, function (_, document) {
                body.append($('<tr>').append(
                    $('<td>').text(docTypeLabel(document.tipo_dte)),
                    $('<td>').addClass('text-nowrap').text(document.numero_control || '—'),
                    $('<td>').text(document.cliente_nombre || '—'),
                    $('<td>').addClass('text-nowrap').text(document.fecha_emision + (document.hora_emision ? ' ' + timePart(document.hora_emision) : '')),
                    $('<td>').addClass('text-nowrap').text(money(document.total_pagar)),
                    $('<td>').text(typeLabel(document.tipo_contingencia))
                ));
            });
            summary.text(pending.length + (pending.length === 1 ? ' documento pendiente' : ' documentos pendientes') + ' de tipo ' + typeLabel($('#cont-type').val()) + '. Se transmitirán al procesar el evento.');
            if (state.pending.length > pending.length) {
                summary.append(' Hay ' + (state.pending.length - pending.length) + ' documento(s) de otros tipos; cree un evento aparte para ellos.');
            }
        }
        if (pending.length > 1000) {
            alert.text('El evento admite hasta 1000 documentos. Divida la contingencia en varios eventos.').removeClass('d-none');
        }
        $('#create-contingency-submit').prop('disabled', pending.length === 0 || pending.length > 1000);
    }

    function loadPending() {
        if (!createModal) return;
        var form = $('#contingency-form');
        var data = {
            action: 'pending',
            f_inicio: $('#cont-f-inicio').val(),
            h_inicio: $('#cont-h-inicio').val(),
            f_fin: $('#cont-f-fin').val(),
            h_fin: $('#cont-h-fin').val()
        };
        if (!data.f_inicio || !data.h_inicio || !data.f_fin || !data.h_fin) {
            state.pending = [];
            renderPending();
            return;
        }
        $('#pending-alert').addClass('d-none');
        $.ajax({ url: api, method: 'GET', dataType: 'json', data: data })
            .done(function (response) {
                state.pending = response.items || [];
                if (state.pending.length) {
                    var distinctTypes = {};
                    $.each(state.pending, function (_, document) { distinctTypes[String(document.tipo_contingencia)] = true; });
                    var keys = Object.keys(distinctTypes);
                    var current = String($('#cont-type').val() || '');
                    if (keys.length === 1 && current !== keys[0]) {
                        $('#cont-type').val(keys[0]);
                        updateReasonRequired();
                    } else if (keys.length > 1 && !state.pending.some(function (document) {
                        return String(document.tipo_contingencia) === current;
                    })) {
                        $('#cont-type').val(keys[0]);
                        updateReasonRequired();
                    }
                }
                renderPending();
            })
            .fail(function (xhr) {
                state.pending = [];
                renderPending();
                var response = xhr.responseJSON || {};
                FacturaSV.showFieldErrors(form, response.errors || {});
                $('#pending-alert').text(response.message || 'No fue posible consultar los documentos del periodo.').removeClass('d-none');
            });
    }

    function openCreate() {
        if (!createModal) return;
        var form = $('#contingency-form');
        form[0].reset();
        $('#contingency-form-alert').addClass('d-none');
        $('#pending-alert').addClass('d-none');
        FacturaSV.showFieldErrors(form, {});
        $('#cont-f-inicio, #cont-f-fin').val(app.data('today'));
        $('#cont-h-inicio').val('00:00');
        $('#cont-h-fin').val('23:59');
        state.pending = [];
        renderPending();
        updateReasonRequired();
        createModal.show();
        loadPending();
    }

    function renderDetail(response) {
        var event = response.event;
        var documents = response.documentos || [];
        var logs = response.logs || [];
        state.currentId = event.id;
        $('#contingency-detail-title').text('Evento de contingencia · ' + (eventStatusLabels[event.estado] || event.estado));
        $('#contingency-detail-code').text(event.codigo_generacion || ('Evento #' + event.id));
        var body = $('#contingency-detail-body').empty();

        var info = $('<dl>').addClass('document-detail-grid');
        info.append(
            detailBlock('Código de generación', event.codigo_generacion),
            detailBlock('Estado', eventStatusLabels[event.estado] || event.estado),
            detailBlock('Periodo', periodText(event)),
            detailBlock('Tipo de contingencia', typeLabel(event.tipo_contingencia)),
            detailBlock('Motivo', event.motivo_contingencia),
            detailBlock('Responsable', event.nombre_responsable),
            detailBlock('Documento responsable', event.tipo_doc_responsable + ' · ' + event.numero_doc_responsable),
            detailBlock('Tipo de establecimiento', event.tipo_establecimiento),
            detailBlock('Fecha de transmisión', event.f_transmision + ' ' + timePart(event.h_transmision)),
            detailBlock('Sello de recepción', event.sello_recepcion),
            detailBlock('Creado', event.created_at)
        );
        body.append(info);

        if (event.error) {
            body.append($('<div>').addClass('alert alert-danger').text('Error del evento: ' + event.error));
        }

        var docsSection = $('<section>').addClass('transmission-history').append($('<h3>').text('Documentos del evento'));
        if (!documents.length) {
            docsSection.append($('<p>').addClass('text-muted').text('El evento no tiene documentos.'));
        } else {
            var table = $('<table>').addClass('table detail-items-table');
            table.append($('<thead>').append($('<tr>').append(
                $('<th>').text('#'), $('<th>').text('Tipo'), $('<th>').text('Número de control'),
                $('<th>').text('Cliente'), $('<th>').text('Fecha'), $('<th>').text('Total'),
                $('<th>').text('Estado'), $('<th>').text('Sello')
            )));
            var tableBody = $('<tbody>');
            $.each(documents, function (_, document) {
                tableBody.append($('<tr>').append(
                    $('<td>').text(document.no_item),
                    $('<td>').text(docTypeLabel(document.tipo_dte)),
                    $('<td>').addClass('text-nowrap').text(document.numero_control || '—'),
                    $('<td>').text(document.cliente_nombre || '—'),
                    $('<td>').addClass('text-nowrap').text(document.fecha_emision),
                    $('<td>').addClass('text-nowrap').text(money(document.total_pagar)),
                    $('<td>').append(documentStatusBadge(document.estado)),
                    $('<td>').addClass('text-nowrap').text(document.sello_recepcion || '—')
                ));
            });
            docsSection.append($('<div>').addClass('table-responsive').append(table.append(tableBody)));
        }
        body.append(docsSection);

        if (event.evento) body.append(jsonPanel('JSON del evento (esquema v4)', event.evento));
        if (event.response) body.append(jsonPanel('Respuesta del MH (simulada)', event.response));

        var history = $('<section>').addClass('transmission-history').append($('<h3>').text('Historial de transmisión'));
        if (!logs.length) {
            history.append($('<p>').addClass('text-muted').text('Todavía no hay intentos registrados.'));
        } else {
            $.each(logs, function (_, log) {
                var entry = $('<details>').addClass('transmission-entry');
                entry.append($('<summary>').text(log.accion + ' · ' + log.estado + ' · ' + log.created_at + (log.usuario ? ' · ' + log.usuario : '')));
                entry.append(jsonPanel('Solicitud', log.request), jsonPanel('Respuesta', log.response));
                history.append(entry);
            });
        }
        body.append(history);

        var actions = $('#contingency-detail-actions').empty();
        actions.append($('<button>').attr({ type: 'button', 'data-bs-dismiss': 'modal' }).addClass('btn btn-outline-secondary').text('Cerrar'));
        actions.append($('<a>').addClass('btn btn-outline-primary').attr({ href: api + '?action=download&id=' + event.id, target: '_blank', rel: 'noopener' }).text('Descargar JSON'));
        var pendingDocuments = documents.some(function (document) { return document.estado === 'EMITIDA' || document.estado === 'RECHAZADA'; });
        if (isAdmin && (event.estado === 'RECHAZADO' || event.estado === 'BORRADOR' || pendingDocuments)) {
            actions.append($('<button>').attr({ type: 'button', 'data-id': event.id }).addClass('btn btn-warning retry-contingency').text(pendingDocuments ? 'Reintentar documentos' : 'Reintentar evento'));
        }
    }

    function openDetail(id) {
        state.currentId = id;
        detailModal.show();
        $('#contingency-detail-body').html('<p class="text-muted">Cargando detalle…</p>');
        $.ajax({ url: api, method: 'GET', dataType: 'json', data: { action: 'get', id: id } })
            .done(renderDetail)
            .fail(function (xhr) {
                $('#contingency-detail-body').append($('<div>').addClass('alert alert-danger').text(requestError(xhr, 'No fue posible cargar el evento.')));
            });
    }

    function retryEvent(id, button) {
        if (!window.confirm('¿Desea reintentar el evento de contingencia? Se volverán a transmitir los documentos pendientes.')) return;
        if (button) button.prop('disabled', true).text('Procesando…');
        $.ajax({ url: api, method: 'POST', dataType: 'json', data: { action: 'retry', id: id, csrf_token: csrf() } })
            .done(function (response) {
                showToast(response.message);
                loadEvents();
                openDetail(id);
            })
            .fail(function (xhr) {
                showToast(requestError(xhr, 'No fue posible reintentar el evento.'));
                if (button) button.prop('disabled', false).text('Reintentar');
            });
    }

    if (createModal) {
        $('#new-contingency-event').on('click', openCreate);
        $('#refresh-pending').on('click', loadPending);
        $('#cont-type').on('change', function () {
            updateReasonRequired();
            renderPending();
        });
        $('#cont-responsible-type').on('change', function () {
            $('#cont-responsible-number').attr('placeholder', $(this).val() === '36' ? '0000-000000-000-0' : '00000000-0');
        });
        $('#cont-f-inicio, #cont-h-inicio, #cont-f-fin, #cont-h-fin').on('change', function () {
            clearTimeout(state.pendingTimer);
            state.pendingTimer = setTimeout(loadPending, 350);
        });
        $('#contingency-form').on('submit', function (event) {
            event.preventDefault();
            if (state.busy) return;
            var form = $(this);
            if (!form[0].checkValidity()) { form[0].reportValidity(); return; }
            var pending = selectedPending();
            if (!pending.length) {
                $('#pending-alert').text('No hay documentos de este tipo en el periodo. Actualice los documentos o cambie el tipo de contingencia.').removeClass('d-none');
                return;
            }
            if (!window.confirm('Se generará el evento y se transmitirán ' + pending.length + ' documento(s). ¿Continuar?')) return;
            state.busy = true;
            $('#contingency-form-alert').addClass('d-none');
            FacturaSV.setLoading(form, true);
            $.ajax({ url: api, method: 'POST', dataType: 'json', data: form.serialize() + '&action=create' })
                .done(function (response) {
                    state.busy = false;
                    FacturaSV.setLoading(form, false);
                    createModal.hide();
                    showToast(response.message);
                    loadEvents();
                    openDetail(response.event.id);
                })
                .fail(function (xhr) {
                    state.busy = false;
                    FacturaSV.setLoading(form, false);
                    var response = xhr.responseJSON || {};
                    FacturaSV.showFieldErrors(form, response.errors || {});
                    showFormError(response.message || 'No fue posible generar el evento.');
                    if (response.errors && response.errors.documentos) {
                        $('#pending-alert').text(response.errors.documentos).removeClass('d-none');
                    }
                    loadEvents();
                });
        });
        updateReasonRequired();
    }

    $('#refresh-contingency-events').on('click', loadEvents);
    $('#contingency-table-body').on('click', '.view-contingency', function () { openDetail($(this).data('id')); });
    $('#contingency-table-body').on('click', '.retry-contingency', function () { retryEvent($(this).data('id'), $(this)); });
    $('#contingency-detail-actions').on('click', '.retry-contingency', function () { retryEvent($(this).data('id'), $(this)); });

    loadEvents();
    if (app.data('open-id')) openDetail(app.data('open-id'));
})(jQuery);
