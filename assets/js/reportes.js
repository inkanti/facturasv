(function ($) {
    'use strict';
    var app = $('#reports-app');

    function money(value) {
        return '$ ' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function numberText(value) {
        return Number(value || 0).toLocaleString('es-SV');
    }
    function formatDate(value) {
        if (!value) return '—';
        var parts = String(value).substring(0, 10).split('-');
        return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : value;
    }
    function showError(message) { $('#report-alert').text(message).removeClass('d-none'); }
    function hideError() { $('#report-alert').addClass('d-none'); }
    function emptyRow(colspan, text) {
        return $('<tr>').append($('<td>').attr('colspan', colspan).addClass('report-empty').text(text));
    }
    function cell(text, className) {
        var value = $('<td>').text(text == null ? '' : text);
        if (className) value.addClass(className);
        return value;
    }
    function renderSummary(summary) {
        $('#summary-gravada').text(money(summary.gravada));
        $('#summary-exenta').text(money(summary.exenta));
        $('#summary-no-suj').text(money(summary.no_suj));
        $('#summary-descuentos').text(money(summary.descuentos));
        $('#summary-iva').text(money(summary.iva));
        $('#summary-iva-retenido').text(money(summary.iva_retenido));
        $('#summary-total').text(money(summary.total));
        $('#summary-pendientes').text(numberText(summary.pendientes));
        $('#report-total').text(money(summary.total));
        $('#report-documents').text(numberText(summary.documentos));
        $('#report-documents-hint').text(summary.documentos === 0 ? 'Sin documentos procesados' : 'Documentos procesados del periodo');
        $('#report-iva').text(money(summary.iva));
        $('#report-invalidated').text(numberText(summary.invalidados));
        $('#report-invalidated-hint').text(summary.invalidados > 0 ? money(summary.invalidados_total) + ' no sumados a las ventas' : 'No se suman a las ventas');
    }
    function renderClients(rows) {
        var body = $('#report-clients').empty();
        if (!rows.length) { body.append(emptyRow(4, 'No hay ventas por cliente en el periodo.')); return; }
        $.each(rows, function (_, row) {
            body.append($('<tr>').append(
                cell(row.nombre), cell(row.numero_documento || 'Sin documento'),
                cell(numberText(row.documentos), 'text-end'), cell(money(row.total), 'text-end')
            ));
        });
    }
    function renderProducts(rows) {
        var body = $('#report-products').empty();
        if (!rows.length) { body.append(emptyRow(4, 'No hay ventas por producto en el periodo.')); return; }
        $.each(rows, function (_, row) {
            body.append($('<tr>').append(
                cell(row.codigo), cell(row.descripcion),
                cell(numberText(row.cantidad), 'text-end'), cell(money(row.total), 'text-end')
            ));
        });
    }
    function renderIva(rows) {
        var body = $('#report-iva-table').empty();
        if (!rows.length) { body.append(emptyRow(5, 'No hay IVA registrado en el periodo.')); return; }
        $.each(rows, function (_, row) {
            body.append($('<tr>').append(
                cell(row.periodo), cell(numberText(row.documentos), 'text-end'),
                cell(money(row.gravada), 'text-end'), cell(money(row.iva), 'text-end'), cell(money(row.total), 'text-end')
            ));
        });
    }
    function renderDays(rows) {
        var body = $('#report-days').empty();
        if (!rows.length) { body.append(emptyRow(4, 'No hay ventas por día en el periodo.')); return; }
        $.each(rows, function (_, row) {
            body.append($('<tr>').append(
                cell(formatDate(row.fecha)), cell(numberText(row.documentos), 'text-end'),
                cell(money(row.iva), 'text-end'), cell(money(row.total), 'text-end')
            ));
        });
    }
    function applyRange(range) {
        if (!range) return;
        $('#report-from').val(range.desde);
        $('#report-to').val(range.hasta);
        $('#report-range-label').text('Periodo: ' + formatDate(range.desde) + ' al ' + formatDate(range.hasta));
    }
    function loadReports() {
        hideError();
        $.ajax({
            url: app.data('api'), method: 'GET', dataType: 'json',
            data: { action: 'data', fecha_desde: $('#report-from').val(), fecha_hasta: $('#report-to').val() }
        }).done(function (response) {
            applyRange(response.range);
            renderSummary(response.summary);
            renderClients(response.by_client || []);
            renderProducts(response.by_product || []);
            renderIva(response.iva || []);
            renderDays(response.by_day || []);
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showError(response.message || 'No fue posible cargar los reportes.');
        });
    }
    function exportCsv(report) {
        var url = app.data('api') + '?action=export&report=' + encodeURIComponent(report)
            + '&fecha_desde=' + encodeURIComponent($('#report-from').val() || '')
            + '&fecha_hasta=' + encodeURIComponent($('#report-to').val() || '');
        window.location.assign(url);
    }

    $('#report-filters').on('submit', function (event) { event.preventDefault(); loadReports(); });
    $('#report-current-month').on('click', function () {
        var today = new Date();
        var first = new Date(today.getFullYear(), today.getMonth(), 1);
        var iso = function (date) {
            return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
        };
        $('#report-from').val(iso(first));
        $('#report-to').val(iso(today));
        loadReports();
    });
    $('[data-report]').on('click', function () { exportCsv($(this).data('report')); });

    loadReports();
})(jQuery);
