(function ($) {
    'use strict';

    function formatNit(value) {
        var digits = value.replace(/\D/g, '').slice(0, 14);
        var formatted = digits.slice(0, 4);

        if (digits.length > 4) {
            formatted += '-' + digits.slice(4, 10);
        }
        if (digits.length > 10) {
            formatted += '-' + digits.slice(10, 13);
        }
        if (digits.length > 13) {
            formatted += '-' + digits.slice(13, 14);
        }

        return formatted;
    }

    function formatDui(value) {
        var digits = value.replace(/\D/g, '').slice(0, 9);
        return digits.length > 8
            ? digits.slice(0, 8) + '-' + digits.slice(8, 9)
            : digits;
    }

    function formatTelefono(value) {
        var digits = value.replace(/\D/g, '').slice(0, 8);
        return digits.length > 4
            ? digits.slice(0, 4) + '-' + digits.slice(4, 8)
            : digits;
    }

    $(document).on('input', '[data-mask="nit"]', function () {
        this.value = formatNit(this.value);
        this.setCustomValidity(
            this.value === '' || /^[0-9]{4}-[0-9]{6}-[0-9]{3}-[0-9]$/.test(this.value)
                ? ''
                : 'Use el formato 0000-000000-000-0.'
        );
    });

    $(document).on('input', '[data-mask="dui"]', function () {
        this.value = formatDui(this.value);
        this.setCustomValidity(
            this.value === '' || /^[0-9]{8}-[0-9]$/.test(this.value)
                ? ''
                : 'Use el formato 00000000-0.'
        );
    });

    $(document).on('input', '[data-mask="telefono"]', function () {
        this.value = formatTelefono(this.value);
        this.setCustomValidity(
            this.value === '' || /^[0-9]{4}-[0-9]{4}$/.test(this.value)
                ? ''
                : 'Use el formato 0000-0000.'
        );
    });

    $('[data-mask="nit"], [data-mask="dui"], [data-mask="telefono"]').each(function () {
        $(this).trigger('input');
    });
})(jQuery);
