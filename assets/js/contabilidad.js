(function ($) {
    'use strict';

    var app = $('#accounting-app');
    var api = app.data('api');
    var canManage = String(app.data('can-manage')) === '1';
    var ENTRY_TYPES = {
        MANUAL: 'Manual', VENTA: 'Venta', NOTA_CREDITO: 'Nota de crédito',
        NOTA_DEBITO: 'Nota de débito', EXPORTACION: 'Exportación',
        SUJETO_EXCLUIDO: 'Sujeto excluido', INVALIDACION: 'Invalidación', AJUSTE: 'Ajuste'
    };
    var state = {
        accounts: [],
        config: null,
        mapping: {},
        types: {},
        natures: {},
        page: 1,
        pages: 1,
        entryIndex: 0,
        booksLoaded: false,
        statementsLoaded: false
    };

    function csrf() { return $('meta[name="csrf-token"]').attr('content'); }
    function showToast(message) {
        $('#app-toast-message').text(message);
        new bootstrap.Toast(document.getElementById('app-toast')).show();
    }
    function showAlert(message) { $('#accounting-alert').text(message).removeClass('d-none'); }
    function hideAlert() { $('#accounting-alert').addClass('d-none').text(''); }
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
    function money(value) {
        var number = Number(value || 0);
        try {
            return '$' + number.toLocaleString('es-SV', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } catch (error) {
            return '$' + number.toFixed(2);
        }
    }
    function numberValue(value) {
        var text = String(value === undefined || value === null ? '' : value).replace(',', '.').trim();
        if (text === '') return 0;
        var number = Number(text);
        return isFinite(number) ? number : 0;
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
    function statusBadge(status) {
        var badge = $('<span>').addClass('accounting-badge');
        if (status === 'CONTABILIZADO') {
            badge.addClass('is-posted').text('Contabilizado');
        } else if (status === 'ANULADO') {
            badge.addClass('is-void').text('Anulado');
        } else {
            badge.text(status || '—');
        }
        return badge;
    }
    function actionButton(label, action, id, className) {
        return $('<button>').attr({ type: 'button', 'data-action': action, 'data-id': id })
            .addClass('btn btn-sm ' + (className || 'btn-outline-secondary')).text(label);
    }
    function setRange(prefix, range) {
        if (!range) return;
        var from = $('#' + prefix + '-from');
        var to = $('#' + prefix + '-to');
        if (from.length && !from.val()) from.val(range.desde);
        if (to.length && !to.val()) to.val(range.hasta);
    }
    function movementAccounts() {
        return state.accounts.filter(function (account) {
            return Number(account.activa) === 1 && Number(account.permite_movimiento) === 1;
        });
    }
    function accountLabel(account) {
        return account.codigo + ' — ' + account.nombre;
    }

    function initializeRanges() {
        var today = todayISO();
        var first = today.substring(0, 8) + '01';
        ['entries', 'books', 'statements'].forEach(function (prefix) {
            var from = $('#' + prefix + '-from');
            var to = $('#' + prefix + '-to');
            if (from.length && !from.val()) from.val(first);
            if (to.length && !to.val()) to.val(today);
        });
        if (!$('#balance-asof').val()) $('#balance-asof').val(today);
    }

    function loadBootstrap() {
        hideAlert();
        get('cuentas').done(function (response) {
            state.accounts = response.items || [];
            state.types = response.tipos || {};
            state.natures = response.naturalezas || {};
            fillAccountTypeSelects();
            fillBookAccounts();
            get('config').done(function (configResponse) {
                state.config = configResponse.config || null;
                state.mapping = configResponse.mapping || {};
                fillConfigSelects();
                initializeRanges();
                renderAccounts();
                loadResumen();
                loadEntries();
            }).fail(function (xhr) {
                showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar la configuración contable.');
            });
        }).fail(function (xhr) {
            showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar el catálogo contable.');
        });
    }

    function fillAccountTypeSelects() {
        var typeSelect = $('#account-type').empty();
        $.each(state.types, function (value, label) {
            typeSelect.append($('<option>').val(value).text(label));
        });
        var natureSelect = $('#account-nature').empty();
        $.each(state.natures, function (value, label) {
            natureSelect.append($('<option>').val(value).text(label));
        });
        typeSelect.val('ACTIVO');
        natureSelect.val('DEUDORA');
    }

    function fillBookAccounts() {
        var select = $('#books-account').empty();
        $.each(state.accounts, function (_, account) {
            select.append($('<option>').val(account.id).text(accountLabel(account)));
        });
    }

    function fillConfigSelects() {
        $.each(state.mapping, function (field, definition) {
            var select = $('#config-' + field);
            if (!select.length) return;
            select.empty();
            $.each(state.accounts, function (_, account) {
                if (Number(account.activa) !== 1 || Number(account.permite_movimiento) !== 1) return;
                if (definition.tipos.indexOf(account.tipo) === -1) return;
                select.append($('<option>').val(account.id).text(accountLabel(account)));
            });
            if (state.config && state.config[field]) {
                select.val(String(state.config[field]));
            }
        });
    }

    function updatePendingBanner(pending) {
        if (pending > 0) {
            $('#accounting-pending-text').text(pending + (pending === 1
                ? ' documento procesado o invalidado aún no tiene asiento contable.'
                : ' documentos procesados o invalidados aún no tienen asiento contable.'));
            $('#accounting-pending').removeClass('d-none');
        } else {
            $('#accounting-pending').addClass('d-none');
        }
        $('#acc-pending-count').text(String(pending));
    }

    function renderResumen(data) {
        $('#acc-kpi-entries').text(String(data.asientos || 0));
        $('#acc-kpi-debit').text(money(data.debe));
        $('#acc-kpi-credit').text(money(data.haber));
        $('#acc-kpi-result').text(money(data.resultado));
        $('#acc-kpi-period').text(data.periodo ? 'Del ' + dateLabel(data.periodo.desde) + ' al ' + dateLabel(data.periodo.hasta) : '—');
        $('#acc-accounts-active').text(String(data.cuentas_activas || 0));
        $('#acc-accounts-total').text(String(data.cuentas || 0));
        $('#acc-period-label').text(data.periodo ? dateLabel(data.periodo.desde) + ' — ' + dateLabel(data.periodo.hasta) : '—');
        updatePendingBanner(Number(data.pendientes || 0));
        if (data.periodo) {
            setRange('entries', data.periodo);
            setRange('books', data.periodo);
            setRange('statements', data.periodo);
            if (!$('#balance-asof').val()) $('#balance-asof').val(data.periodo.hasta);
        }

        var body = $('#acc-recent-body').empty();
        if (!data.recientes || !data.recientes.length) {
            emptyRow(body, 5, 'Todavía no hay asientos contables.');
            return;
        }
        $.each(data.recientes, function (_, entry) {
            var row = $('<tr>').addClass('accounting-clickable').attr('data-action', 'view').attr('data-id', entry.id);
            row.append($('<td>').text(entry.numero));
            row.append($('<td>').addClass('text-nowrap').text(dateLabel(entry.fecha)));
            row.append($('<td>').text(entry.concepto));
            row.append($('<td>').addClass('text-end').text(money(entry.total_debe)));
            row.append($('<td>').addClass('text-end').text(money(entry.total_haber)));
            body.append(row);
        });
    }

    function loadResumen() {
        get('resumen').done(renderResumen).fail(function (xhr) {
            showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar el resumen contable.');
        });
    }

    function entryFilters() {
        return $('#entry-filters').serialize();
    }

    function loadEntries() {
        var data = entryFilters() + '&page=' + state.page;
        get('asientos', data).done(function (response) {
            renderEntries(response);
        }).fail(function (xhr) {
            showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar los asientos.');
        });
    }

    function renderEntries(response) {
        var body = $('#entries-body').empty();
        var items = response.items || [];
        if (!items.length) {
            emptyRow(body, 9, 'No hay asientos para los filtros seleccionados.');
        } else {
            $.each(items, function (_, entry) {
                var row = $('<tr>');
                row.append($('<td>').text(entry.numero));
                row.append($('<td>').addClass('text-nowrap').text(dateLabel(entry.fecha)));
                row.append($('<td>').text(entry.concepto));
                row.append($('<td>').text(ENTRY_TYPES[entry.tipo] || entry.tipo));
                var document = entry.numero_control ? $('<span>').addClass('accounting-document').text(entry.numero_control) : $('<span>').text('—');
                row.append($('<td>').append(document));
                row.append($('<td>').addClass('text-end').text(money(entry.total_debe)));
                row.append($('<td>').addClass('text-end').text(money(entry.total_haber)));
                row.append($('<td>').append(statusBadge(entry.estado)));
                var actions = $('<td>').addClass('text-end text-nowrap');
                actions.append(actionButton('Ver', 'view', entry.id));
                if (entry.estado === 'CONTABILIZADO' && (entry.tipo === 'MANUAL' || entry.tipo === 'AJUSTE')) {
                    actions.append(' ').append(actionButton('Anular', 'void', entry.id, 'btn-outline-danger'));
                }
                row.append(actions);
                body.append(row);
            });
        }
        state.page = response.pagination ? response.pagination.page : 1;
        state.pages = response.pagination ? response.pagination.pages : 1;
        $('#entries-page-label').text('Página ' + state.page + ' de ' + state.pages + ' · ' +
            (response.pagination ? response.pagination.total : 0) + ' asientos');
        $('#entries-prev').prop('disabled', state.page <= 1);
        $('#entries-next').prop('disabled', state.page >= state.pages);
    }

    function openEntryDetail(id) {
        get('asiento', { id: id }).done(function (response) {
            var entry = response.asiento;
            if (!entry) return;
            var body = $('#entry-detail-body').empty();
            var list = $('<dl>').addClass('accounting-detail-list');
            var add = function (label, value) {
                list.append($('<div>').append($('<dt>').text(label), $('<dd>').text(value || '—')));
            };
            add('Asiento', '#' + entry.numero);
            add('Fecha', dateLabel(entry.fecha));
            add('Concepto', entry.concepto);
            add('Tipo', ENTRY_TYPES[entry.tipo] || entry.tipo);
            add('Estado', entry.estado === 'CONTABILIZADO' ? 'Contabilizado' : 'Anulado');
            add('Documento', entry.numero_control || 'Manual');
            add('Registrado por', entry.usuario_nombre || '—');
            if (entry.estado === 'ANULADO') {
                add('Motivo de anulación', entry.motivo_anulacion || '—');
                add('Anulado por', entry.anulado_por_nombre || '—');
            }
            body.append(list);
            var table = $('<table>').addClass('table accounting-table accounting-detail-table align-middle mb-0');
            table.append($('<thead>').append($('<tr>').append(
                $('<th>').text('Código'),
                $('<th>').text('Cuenta'),
                $('<th>').text('Detalle'),
                $('<th>').addClass('text-end').text('Debe'),
                $('<th>').addClass('text-end').text('Haber')
            )));
            var tbody = $('<tbody>');
            $.each(entry.partidas || [], function (_, line) {
                tbody.append($('<tr>').append(
                    $('<td>').text(line.cuenta_codigo),
                    $('<td>').text(line.cuenta_nombre),
                    $('<td>').text(line.descripcion),
                    $('<td>').addClass('text-end').text(money(line.debe)),
                    $('<td>').addClass('text-end').text(money(line.haber))
                ));
            });
            table.append(tbody);
            table.append($('<tfoot>').append($('<tr>').append(
                $('<td>').attr('colspan', 3).text('Totales'),
                $('<td>').addClass('text-end').text(money(entry.total_debe)),
                $('<td>').addClass('text-end').text(money(entry.total_haber))
            )));
            body.append($('<div>').addClass('table-responsive').append(table));
            new bootstrap.Modal(document.getElementById('entry-detail-modal')).show();
        }).fail(function (xhr) {
            showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar el asiento.');
        });
    }

    function addEntryLine() {
        var index = state.entryIndex++;
        var row = $('<tr>');
        var account = $('<select>').addClass('form-select form-select-sm line-account')
            .attr('name', 'lineas[' + index + '][cuenta_id]');
        account.append($('<option>').val('').text('Seleccione…'));
        $.each(movementAccounts(), function (_, item) {
            account.append($('<option>').val(item.id).text(accountLabel(item)));
        });
        row.append($('<td>').append(account));
        row.append($('<td>').append($('<input>').addClass('form-control form-control-sm line-description')
            .attr({ type: 'text', name: 'lineas[' + index + '][descripcion]', maxlength: 200 })));
        row.append($('<td>').append($('<input>').addClass('form-control form-control-sm text-end line-debit')
            .attr({ type: 'text', name: 'lineas[' + index + '][debe]', inputmode: 'decimal', autocomplete: 'off' })));
        row.append($('<td>').append($('<input>').addClass('form-control form-control-sm text-end line-credit')
            .attr({ type: 'text', name: 'lineas[' + index + '][haber]', inputmode: 'decimal', autocomplete: 'off' })));
        row.append($('<td>').addClass('text-end').append($('<button>').attr('type', 'button')
            .addClass('btn btn-sm btn-outline-danger line-remove').attr('aria-label', 'Quitar línea').text('×')));
        $('#entry-lines').append(row);
        updateEntryTotals();
    }

    function updateEntryTotals() {
        var debit = 0;
        var credit = 0;
        $('#entry-lines tr').each(function () {
            debit += numberValue($(this).find('.line-debit').val());
            credit += numberValue($(this).find('.line-credit').val());
        });
        var difference = Math.round((debit - credit) * 100) / 100;
        $('#entry-total-debe').text(money(debit));
        $('#entry-total-haber').text(money(credit));
        $('#entry-difference').text(money(difference)).toggleClass('text-danger', Math.abs(difference) > 0.004);
        var canSubmit = Math.abs(difference) <= 0.004 && debit > 0.004;
        $('#entry-form button[type="submit"]').prop('disabled', !canSubmit);
    }

    function openEntryModal() {
        var form = $('#entry-form')[0];
        form.reset();
        $('#entry-alert').addClass('d-none').text('');
        $('#entry-lines').empty();
        FacturaSV.showFieldErrors($('#entry-form'), {});
        $('#entry-date').val(todayISO());
        state.entryIndex = 0;
        addEntryLine();
        addEntryLine();
        updateEntryTotals();
        new bootstrap.Modal(document.getElementById('entry-modal')).show();
    }

    function openVoidModal(id) {
        get('asiento', { id: id }).done(function (response) {
            var entry = response.asiento;
            if (!entry) return;
            $('#void-id').val(entry.id);
            $('#void-summary').text('Asiento #' + entry.numero + ' · ' + entry.concepto + ' · ' + money(entry.total_debe));
            $('#void-reason').val('');
            $('#void-form').find('.is-invalid').removeClass('is-invalid');
            $('#void-form').find('[data-error-for]').text('');
            new bootstrap.Modal(document.getElementById('void-modal')).show();
        }).fail(function (xhr) {
            showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar el asiento.');
        });
    }

    function renderAccounts() {
        var query = String($('#account-search').val() || '').toLowerCase().trim();
        var body = $('#accounts-body').empty();
        var accounts = state.accounts.filter(function (account) {
            if (!query) return true;
            return String(account.codigo).toLowerCase().indexOf(query) !== -1 ||
                String(account.nombre).toLowerCase().indexOf(query) !== -1;
        });
        if (!accounts.length) {
            emptyRow(body, 7, 'No hay cuentas que coincidan con la búsqueda.');
            return;
        }
        $.each(accounts, function (_, account) {
            var row = $('<tr>');
            row.append($('<td>').text(account.codigo));
            row.append($('<td>').text(account.nombre));
            row.append($('<td>').text(state.types[account.tipo] || account.tipo));
            row.append($('<td>').text(state.natures[account.naturaleza] || account.naturaleza));
            row.append($('<td>').text(Number(account.permite_movimiento) === 1 ? 'Sí' : 'No'));
            row.append($('<td>').append(Number(account.activa) === 1
                ? $('<span>').addClass('accounting-badge is-posted').text('Activa')
                : $('<span>').addClass('accounting-badge is-void').text('Inactiva')));
            var actions = $('<td>').addClass('text-end text-nowrap');
            if (canManage) {
                actions.append(actionButton('Editar', 'edit-account', account.id));
                actions.append(' ').append(actionButton(Number(account.activa) === 1 ? 'Desactivar' : 'Activar',
                    'toggle-account', account.id, Number(account.activa) === 1 ? 'btn-outline-danger' : 'btn-outline-success'));
            } else {
                actions.append($('<span>').addClass('text-muted small').text('Solo lectura'));
            }
            row.append(actions);
            body.append(row);
        });
    }

    function openAccountModal(id) {
        var form = $('#account-form')[0];
        form.reset();
        $('#account-form').find('.is-invalid').removeClass('is-invalid');
        $('#account-form').find('[data-error-for]').text('');
        if (id) {
            var account = state.accounts.filter(function (item) { return Number(item.id) === Number(id); })[0];
            if (!account) return;
            $('#account-modal-title').text('Editar cuenta');
            $('#account-id').val(account.id);
            $('#account-code').val(account.codigo).prop('disabled', true);
            $('#account-name').val(account.nombre);
            $('#account-type').val(account.tipo);
            $('#account-nature').val(account.naturaleza);
            $('#account-movement').val(String(account.permite_movimiento));
            $('#account-active').val(String(account.activa));
        } else {
            $('#account-modal-title').text('Nueva cuenta');
            $('#account-id').val('');
            $('#account-code').val('').prop('disabled', false);
            $('#account-name').val('');
            $('#account-type').val('ACTIVO');
            $('#account-nature').val('DEUDORA');
            $('#account-movement').val('1');
            $('#account-active').val('1');
        }
        new bootstrap.Modal(document.getElementById('account-modal')).show();
    }

    function toggleAccount(id) {
        var account = state.accounts.filter(function (item) { return Number(item.id) === Number(id); })[0];
        if (!account) return;
        var activate = Number(account.activa) !== 1;
        if (!window.confirm(activate
            ? '¿Desea activar la cuenta ' + account.codigo + ' ' + account.nombre + '?'
            : '¿Desea desactivar la cuenta ' + account.codigo + ' ' + account.nombre + '?')) return;
        post('cuenta-toggle', { id: id, activa: activate ? '1' : '0' }).done(function (response) {
            showToast(response.message);
            reloadAccounts();
        }).fail(function (xhr) {
            showAlert(firstError(xhr.responseJSON) || 'No fue posible cambiar el estado de la cuenta.');
        });
    }

    function reloadAccounts() {
        get('cuentas').done(function (response) {
            state.accounts = response.items || [];
            state.types = response.tipos || state.types;
            state.natures = response.naturalezas || state.natures;
            fillBookAccounts();
            fillConfigSelects();
            renderAccounts();
            loadResumen();
            if (state.booksLoaded) loadBooks();
        }).fail(function (xhr) {
            showAlert((xhr.responseJSON || {}).message || 'No fue posible recargar el catálogo.');
        });
    }

    function renderDiary(data) {
        var body = $('#diary-body').empty();
        var count = 0;
        $.each(data.entries || [], function (_, entry) {
            $.each(entry.partidas || [], function (_, line) {
                count++;
                body.append($('<tr>').append(
                    $('<td>').addClass('text-nowrap').text(dateLabel(entry.fecha)),
                    $('<td>').text(entry.numero),
                    $('<td>').text(entry.concepto),
                    $('<td>').text(line.cuenta_codigo + ' ' + line.cuenta_nombre),
                    $('<td>').addClass('text-end').text(money(line.debe)),
                    $('<td>').addClass('text-end').text(money(line.haber))
                ));
            });
        });
        if (count === 0) emptyRow(body, 6, 'No hay movimientos en el periodo.');
        $('#diary-total-debit').text(money(data.total_debe));
        $('#diary-total-credit').text(money(data.total_haber));
        $('#diary-truncated').toggleClass('d-none', !data.truncated);
    }

    function renderLedger(data) {
        var body = $('#ledger-body').empty();
        $('#ledger-account-label').text(data.cuenta ? data.cuenta.codigo + ' ' + data.cuenta.nombre : '');
        if (!data.rows || !data.rows.length) {
            emptyRow(body, 7, 'La cuenta no tiene movimientos en el periodo.');
        } else {
            $.each(data.rows, function (_, row) {
                body.append($('<tr>').append(
                    $('<td>').addClass('text-nowrap').text(dateLabel(row.fecha)),
                    $('<td>').text(row.numero),
                    $('<td>').text(row.concepto),
                    $('<td>').text(row.descripcion),
                    $('<td>').addClass('text-end').text(money(row.debe)),
                    $('<td>').addClass('text-end').text(money(row.haber)),
                    $('<td>').addClass('text-end').text(money(row.saldo))
                ));
            });
        }
        $('#ledger-initial').text(money(data.saldo_inicial));
        $('#ledger-debit').text(money(data.total_debe));
        $('#ledger-credit').text(money(data.total_haber));
        $('#ledger-final').text(money(data.saldo_final));
    }

    function renderTrial(data) {
        var body = $('#trial-body').empty();
        if (!data.rows || !data.rows.length) {
            emptyRow(body, 6, 'No hay movimientos en el periodo.');
        } else {
            $.each(data.rows, function (_, row) {
                body.append($('<tr>').append(
                    $('<td>').text(row.codigo),
                    $('<td>').text(row.nombre),
                    $('<td>').addClass('text-end').text(money(row.saldo_inicial)),
                    $('<td>').addClass('text-end').text(money(row.debe)),
                    $('<td>').addClass('text-end').text(money(row.haber)),
                    $('<td>').addClass('text-end').text(money(row.saldo_final))
                ));
            });
        }
        $('#trial-total-debit').text(money(data.total_debe));
        $('#trial-total-credit').text(money(data.total_haber));
        $('#trial-total-debtor').text(money(data.total_deudor));
        $('#trial-total-creditor').text(money(data.total_acreedor));
    }

    function loadBooks() {
        if (!state.accounts.length) return;
        var accountId = $('#books-account').val() || (state.accounts[0] && state.accounts[0].id);
        var data = $('#books-filters').serialize();
        if (data.indexOf('cuenta_id=') === -1) {
            data += '&cuenta_id=' + encodeURIComponent(accountId);
        }
        var pending = 3;
        var results = {};
        var finish = function (key, response) {
            results[key] = response;
            pending--;
            if (pending > 0) return;
            if (results.diario) renderDiary(results.diario);
            if (results.mayor) renderLedger(results.mayor);
            if (results.balanza) renderTrial(results.balanza);
            if (results.diario) {
                $('#books-range-label').text(dateLabel(results.diario.range.desde) + ' — ' + dateLabel(results.diario.range.hasta));
            }
            state.booksLoaded = true;
        };
        var fail = function (xhr) {
            showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar los libros contables.');
        };
        get('diario', data).done(function (response) { finish('diario', response); }).fail(fail);
        get('mayor', data).done(function (response) { finish('mayor', response); }).fail(fail);
        get('balanza', data).done(function (response) { finish('balanza', response); }).fail(fail);
    }

    function renderIncome(data) {
        var body = $('#income-body').empty();
        var groups = [
            ['INGRESO', 'Ingresos', data.ingresos],
            ['COSTO', 'Costos', data.costos],
            ['GASTO', 'Gastos', data.gastos]
        ];
        var count = 0;
        $.each(groups, function (_, group) {
            if (!group[2] || !group[2].length) return;
            body.append($('<tr>').addClass('accounting-group-row').append(
                $('<td>').attr('colspan', 4).text(group[1])
            ));
            $.each(group[2], function (_, row) {
                count++;
                body.append($('<tr>').append(
                    $('<td>').text(group[1]),
                    $('<td>').text(row.codigo),
                    $('<td>').text(row.nombre),
                    $('<td>').addClass('text-end').text(money(row.saldo))
                ));
            });
        });
        if (count === 0) emptyRow(body, 4, 'No hay ingresos, costos ni gastos en el periodo.');
        $('#income-total-income').text(money(data.total_ingresos));
        $('#income-total-cost').text(money(data.total_costos));
        $('#income-total-expense').text(money(data.total_gastos));
        $('#income-gross').text(money(data.utilidad_bruta));
        $('#income-net').text(money(data.utilidad_neta));
    }

    function renderBalance(data) {
        var body = $('#balance-body').empty();
        var groups = [
            ['ACTIVO', 'Activo', data.activo],
            ['PASIVO', 'Pasivo', data.pasivo],
            ['PATRIMONIO', 'Patrimonio', data.patrimonio]
        ];
        var count = 0;
        $.each(groups, function (_, group) {
            if (!group[2] || !group[2].length) return;
            body.append($('<tr>').addClass('accounting-group-row').append(
                $('<td>').attr('colspan', 4).text(group[1])
            ));
            $.each(group[2], function (_, row) {
                count++;
                body.append($('<tr>').append(
                    $('<td>').text(group[1]),
                    $('<td>').text(row.codigo),
                    $('<td>').text(row.nombre),
                    $('<td>').addClass('text-end').text(money(row.saldo))
                ));
            });
        });
        if (data.resultado_ejercicio) {
            count++;
            body.append($('<tr>').append(
                $('<td>').text('Patrimonio'),
                $('<td>').text(''),
                $('<td>').text('Resultado del ejercicio (calculado)'),
                $('<td>').addClass('text-end').text(money(data.resultado_ejercicio))
            ));
        }
        if (count === 0) emptyRow(body, 4, 'No hay saldos contables a la fecha.');
        $('#balance-total-assets').text(money(data.total_activo));
        $('#balance-total-liabilities').text(money(data.total_pasivo));
        $('#balance-total-equity').text(money(data.total_patrimonio));
        $('#balance-result').text(money(data.resultado_ejercicio));
        $('#balance-total-liabilities-equity').text(money(data.total_pasivo_patrimonio));
        $('#balance-difference').text(money(data.diferencia)).toggleClass('text-danger', Math.abs(Number(data.diferencia)) > 0.004);
        $('#balance-asof').val(data.asof);
    }

    function loadStatements() {
        var data = $('#statements-filters').serialize();
        var pending = 2;
        var results = {};
        var finish = function (key, response) {
            results[key] = response;
            pending--;
            if (pending > 0) return;
            if (results.resultados) renderIncome(results.resultados);
            if (results.balance) renderBalance(results.balance);
            if (results.resultados && results.resultados.range) {
                $('#statements-range-label').text(dateLabel(results.resultados.range.desde) + ' — ' + dateLabel(results.resultados.range.hasta));
            }
            state.statementsLoaded = true;
        };
        var fail = function (xhr) {
            showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar los estados financieros.');
        };
        get('resultados', data).done(function (response) { finish('resultados', response); }).fail(fail);
        get('balance', data).done(function (response) { finish('balance', response); }).fail(fail);
    }

    function loadBalanceOnly() {
        var date = $('#balance-asof').val() || todayISO();
        get('balance', { fecha_hasta: date }).done(renderBalance).fail(function (xhr) {
            showAlert((xhr.responseJSON || {}).message || 'No fue posible cargar el balance general.');
        });
    }

    function exportReport(report) {
        var params;
        if (report === 'diario' || report === 'balanza' || report === 'mayor') {
            params = $('#books-filters').serialize();
        } else if (report === 'asientos') {
            params = $('#entry-filters').serialize();
        } else {
            params = $('#statements-filters').serialize();
            if (report === 'balance') {
                params = 'fecha_hasta=' + encodeURIComponent($('#balance-asof').val() || todayISO());
            }
        }
        window.location.assign(api + '?action=export&report=' + encodeURIComponent(report) + '&' + params);
    }

    /* Eventos */

    $('#entry-filters').on('submit', function (event) {
        event.preventDefault();
        state.page = 1;
        loadEntries();
    });
    $('#entries-prev').on('click', function () {
        if (state.page <= 1) return;
        state.page--;
        loadEntries();
    });
    $('#entries-next').on('click', function () {
        if (state.page >= state.pages) return;
        state.page++;
        loadEntries();
    });
    $('#entries-export').on('click', function () { exportReport('asientos'); });
    var searchTimer = null;
    $('#entries-query').on('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(function () {
            state.page = 1;
            loadEntries();
        }, 400);
    });

    $('#entry-new').on('click', openEntryModal);
    $('#entry-add-line').on('click', addEntryLine);
    $('#entry-lines').on('input', '.line-debit', function () {
        if (numberValue($(this).val()) !== 0) $(this).closest('tr').find('.line-credit').val('');
        updateEntryTotals();
    });
    $('#entry-lines').on('input', '.line-credit', function () {
        if (numberValue($(this).val()) !== 0) $(this).closest('tr').find('.line-debit').val('');
        updateEntryTotals();
    });
    $('#entry-lines').on('click', '.line-remove', function () {
        if ($('#entry-lines tr').length <= 1) return;
        $(this).closest('tr').remove();
        updateEntryTotals();
    });
    $('#entry-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        var element = form[0];
        if (!element.checkValidity()) {
            element.reportValidity();
            return;
        }
        if ($('#entry-form button[type="submit"]').prop('disabled')) return;
        $('#entry-alert').addClass('d-none').text('');
        FacturaSV.showFieldErrors(form, {});
        FacturaSV.setLoading(form, true);
        post('asiento-create', form.serialize()).done(function (response) {
            FacturaSV.setLoading(form, false);
            bootstrap.Modal.getInstance(document.getElementById('entry-modal')).hide();
            showToast(response.message);
            state.page = 1;
            loadResumen();
            loadEntries();
            if (state.booksLoaded) loadBooks();
            if (state.statementsLoaded) loadStatements();
        }).fail(function (xhr) {
            FacturaSV.setLoading(form, false);
            var response = xhr.responseJSON || {};
            FacturaSV.showFieldErrors(form, response.errors || {});
            $('#entry-alert').text(firstError(response) || 'No fue posible guardar el asiento.').removeClass('d-none');
        });
    });

    $('#entries-body, #acc-recent-body').on('click', '[data-action="view"], tr.accounting-clickable', function () {
        openEntryDetail($(this).data('id'));
    });
    $('#entries-body').on('click', '[data-action="void"]', function (event) {
        event.stopPropagation();
        openVoidModal($(this).data('id'));
    });
    $('#void-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        FacturaSV.showFieldErrors(form, {});
        post('asiento-anular', form.serialize()).done(function (response) {
            bootstrap.Modal.getInstance(document.getElementById('void-modal')).hide();
            showToast(response.message);
            loadResumen();
            loadEntries();
            if (state.booksLoaded) loadBooks();
            if (state.statementsLoaded) loadStatements();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            FacturaSV.showFieldErrors(form, response.errors || {});
            showAlert(firstError(response) || 'No fue posible anular el asiento.');
        });
    });

    $('#account-search').on('input', renderAccounts);
    $('#account-new').on('click', function () { openAccountModal(null); });
    $('#account-type').on('change', function () {
        var type = $(this).val();
        var nature = (type === 'PASIVO' || type === 'PATRIMONIO' || type === 'INGRESO') ? 'ACREEDORA' : 'DEUDORA';
        $('#account-nature').val(nature);
    });
    $('#account-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        FacturaSV.showFieldErrors(form, {});
        FacturaSV.setLoading(form, true);
        post('cuenta-save', form.serialize()).done(function (response) {
            FacturaSV.setLoading(form, false);
            bootstrap.Modal.getInstance(document.getElementById('account-modal')).hide();
            showToast(response.message);
            reloadAccounts();
        }).fail(function (xhr) {
            FacturaSV.setLoading(form, false);
            var response = xhr.responseJSON || {};
            FacturaSV.showFieldErrors(form, response.errors || {});
            showAlert(firstError(response) || 'No fue posible guardar la cuenta.');
        });
    });
    $('#accounts-body').on('click', '[data-action="edit-account"]', function () {
        openAccountModal($(this).data('id'));
    });
    $('#accounts-body').on('click', '[data-action="toggle-account"]', function () {
        toggleAccount($(this).data('id'));
    });

    $('#accounting-config-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        if (!canManage) return;
        FacturaSV.showFieldErrors(form, {});
        FacturaSV.setLoading(form, true);
        post('config-save', form.serialize()).done(function (response) {
            FacturaSV.setLoading(form, false);
            showToast(response.message);
            get('config').done(function (configResponse) {
                state.config = configResponse.config;
                state.mapping = configResponse.mapping || state.mapping;
                fillConfigSelects();
            });
        }).fail(function (xhr) {
            FacturaSV.setLoading(form, false);
            var response = xhr.responseJSON || {};
            FacturaSV.showFieldErrors(form, response.errors || {});
            showAlert(firstError(response) || 'No fue posible guardar la configuración contable.');
        });
    });

    $('#books-filters').on('submit', function (event) {
        event.preventDefault();
        loadBooks();
    });
    $('#statements-filters').on('submit', function (event) {
        event.preventDefault();
        loadStatements();
    });
    $('#balance-apply').on('click', loadBalanceOnly);
    $('[data-export]').on('click', function () { exportReport(String($(this).data('export'))); });

    $('#accounting-sync').on('click', function () {
        var button = $(this).prop('disabled', true);
        post('sincronizar').done(function (response) {
            button.prop('disabled', false);
            showToast(response.message);
            loadResumen();
            loadEntries();
            if (state.booksLoaded) loadBooks();
            if (state.statementsLoaded) loadStatements();
        }).fail(function (xhr) {
            button.prop('disabled', false);
            showAlert((xhr.responseJSON || {}).message || 'No fue posible generar los asientos pendientes.');
        });
    });

    $('[data-bs-toggle="tab"]').on('shown.bs.tab', function (event) {
        var target = String($(event.target).data('bs-target') || '');
        if (target === '#tab-catalogo') renderAccounts();
        if (target === '#tab-libros' && !state.booksLoaded) loadBooks();
        if (target === '#tab-estados' && !state.statementsLoaded) loadStatements();
    });

    loadBootstrap();
})(jQuery);
