(function ($) {
    'use strict';

    function showFieldErrors(form, errors) {
        form.find('.is-invalid').removeClass('is-invalid');
        form.find('[data-error-for]').text('');

        $.each(errors || {}, function (field, message) {
            var input = form.find('[name="' + field + '"]');
            input.addClass('is-invalid');
            form.find('[data-error-for="' + field + '"]').text(message);
        });
    }

    function setLoading(form, loading) {
        var button = form.find('button[type="submit"]');
        button.prop('disabled', loading);
        button.find('.button-label').toggleClass('d-none', loading);
        button.find('.button-loading').toggleClass('d-none', !loading);
    }

    function debounce(callback, delay) {
        var timer = null;
        return function () {
            var context = this;
            var args = arguments;
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                callback.apply(context, args);
            }, delay || 300);
        };
    }

    window.FacturaSV = {
        showFieldErrors: showFieldErrors,
        setLoading: setLoading,
        debounce: debounce
    };

    $('#sidebar-toggle, #sidebar-overlay').on('click', function () {
        var open = !$('body').hasClass('sidebar-open');
        $('body').toggleClass('sidebar-open', open);
        $('#sidebar-toggle').attr('aria-expanded', open ? 'true' : 'false');
    });

    $('#logout-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        var button = form.find('button[type="submit"]');
        button.prop('disabled', true);

        $.ajax({
            url: form.attr('action'),
            method: 'POST',
            data: form.serialize(),
            dataType: 'json'
        }).done(function (response) {
            if (response.success) {
                window.location.assign(response.redirect);
            }
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            window.alert(response.message || 'No fue posible cerrar la sesión. Inténtelo nuevamente.');
            button.prop('disabled', false);
        });
    });
})(jQuery);
