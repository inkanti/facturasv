(function ($) {
    'use strict';
    var app = $('#dashboard-app');
    var statusLabels = { BORRADOR: 'Borrador', EMITIDA: 'Emitida', PROCESADA: 'Procesada', RECHAZADA: 'Rechazada', INVALIDADA: 'Invalidada' };
    var typeLabels = { '01': 'Factura', '03': 'Crédito Fiscal', '05': 'Nota de Crédito', '06': 'Nota de Débito', '11': 'Exportación', '14': 'Sujeto Excluido', 'IN': 'Comprobante interno' };

    function money(value) {
        return '$ ' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function showError(message) { $('#dashboard-alert').text(message).removeClass('d-none'); }
    function statusBadge(status) {
        return $('<span>').addClass('document-status status-' + String(status).toLowerCase()).text(statusLabels[status] || status);
    }
    function formatDate(value) {
        if (!value) return '—';
        var parts = String(value).substring(0, 10).split('-');
        return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : value;
    }
    function variation(current, previous) {
        current = Number(current || 0);
        previous = Number(previous || 0);
        if (previous <= 0) return current > 0 ? 'Sin base del mes anterior' : 'Sin movimientos';
        var change = ((current - previous) / previous) * 100;
        var arrow = change > 0 ? '▲' : (change < 0 ? '▼' : '—');
        return arrow + ' ' + Math.abs(change).toFixed(1) + '% frente al mes anterior';
    }
    function renderChart(series) {
        var chart = $('#dashboard-chart').empty();
        var values = series.map(function (item) { return Number(item.total || 0); });
        var max = Math.max.apply(Math, values.concat([0]));
        if (max <= 0) { chart.addClass('d-none'); $('#dashboard-chart-empty').removeClass('d-none'); return; }
        chart.removeClass('d-none');
        $('#dashboard-chart-empty').addClass('d-none');
        $.each(series, function (_, item) {
            var percent = max > 0 ? (Number(item.total || 0) / max) * 100 : 0;
            var level = Math.max(0, Math.min(100, Math.round(percent / 5) * 5));
            var column = $('<div>').addClass('chart-bar-item');
            column.append($('<span>').addClass('chart-bar-value').text(item.total > 0 ? money(item.total) : '—'));
            column.append($('<div>').addClass('chart-bar chart-bar-h' + level).attr('title', item.periodo + ': ' + money(item.total)));
            column.append($('<span>').addClass('chart-bar-label').text(item.etiqueta));
            chart.append(column);
        });
    }
    function renderTop(clients) {
        var list = $('#dashboard-top').empty();
        if (!clients.length) { list.addClass('d-none'); $('#dashboard-top-empty').removeClass('d-none'); return; }
        list.removeClass('d-none');
        $('#dashboard-top-empty').addClass('d-none');
        $.each(clients, function (index, client) {
            var item = $('<li>');
            item.append($('<span>').addClass('rank-position').text(index + 1));
            item.append($('<span>').addClass('rank-name').text(client.nombre));
            item.append($('<span>').addClass('rank-amount').text(money(client.total)));
            list.append(item);
        });
    }
    function renderRecent(documents) {
        var body = $('#dashboard-recent').empty();
        if (!documents.length) {
            body.append($('<tr>').append($('<td>').attr('colspan', 5).addClass('text-center text-muted py-4').text('Todavía no hay documentos registrados.')));
            return;
        }
        $.each(documents, function (_, document) {
            var identity = $('<td>');
            var link = $('<a>').attr('href', app.data('documents-url') + '?documento=' + document.id).addClass('text-decoration-none');
            link.append($('<strong>').text(typeLabels[document.tipo_dte] || document.tipo_dte));
            link.append($('<span>').addClass('document-secondary').text(document.numero_control || 'Borrador #' + document.id));
            identity.append(link);
            var row = $('<tr>');
            row.append(identity);
            row.append($('<td>').text(document.cliente_nombre));
            row.append($('<td>').addClass('text-nowrap').text(formatDate(document.fecha_emision)));
            row.append($('<td>').addClass('text-end text-nowrap').text(money(document.total_pagar)));
            row.append($('<td>').append(statusBadge(document.estado)));
            body.append(row);
        });
    }
    function loadDashboard() {
        $('#dashboard-alert').addClass('d-none');
        $.ajax({ url: app.data('api'), method: 'GET', dataType: 'json', data: { action: 'dashboard' } })
            .done(function (response) {
                var period = response.periodo || {};
                $('#dashboard-period').text('Resumen del ' + formatDate(period.desde) + ' al ' + formatDate(period.hasta) + '.');
                $('#kpi-ingresos').text(money(response.ingresos));
                $('#kpi-ingresos-hint').text(variation(response.ingresos, response.ingresos_anterior));
                $('#kpi-documentos').text(Number(response.documentos).toLocaleString('es-SV'));
                $('#kpi-documentos-hint').text(variation(response.documentos, response.documentos_anterior) + (response.invalidados ? ' · ' + response.invalidados + ' invalidado(s)' : ''));
                $('#kpi-iva').text(money(response.iva));
                $('#kpi-iva-hint').text(variation(response.iva, response.iva_anterior));
                $('#kpi-pendientes').text(Number(response.pendientes).toLocaleString('es-SV'));
                renderChart(response.serie || []);
                renderTop(response.top_clientes || []);
                renderRecent(response.recientes || []);
            })
            .fail(function (xhr) {
                var response = xhr.responseJSON || {};
                showError(response.message || 'No fue posible cargar el dashboard.');
            });
    }

    loadDashboard();
})(jQuery);
