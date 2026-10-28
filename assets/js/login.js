(function ($) {
    'use strict';

    var form = $('#login-form');
    var identifier = $('#usuario');
    var hint = $('#usuario-hint');
    var password = $('#password');
    var toggle = $('#toggle-password');

    function validateIdentifier(showEmpty) {
        var value = $.trim(identifier.val());
        var valid = true;
        hint.removeClass('is-error');
        if (value === '') {
            if (showEmpty) {
                hint.addClass('is-error').text('Ingrese su usuario o correo.');
            } else {
                hint.text('');
            }
            valid = false;
        } else if (value.indexOf('@') !== -1 && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
            hint.addClass('is-error').text('Ese correo no parece válido.');
            valid = false;
        } else {
            hint.text('');
        }
        return valid;
    }

    identifier.on('blur', function () { validateIdentifier(false); });
    identifier.on('input', function () {
        if (hint.hasClass('is-error')) {
            validateIdentifier(false);
        }
    });

    toggle.on('click', function () {
        var showing = password.attr('type') === 'text';
        password.attr('type', showing ? 'password' : 'text');
        toggle.attr('aria-pressed', showing ? 'false' : 'true')
            .attr('aria-label', showing ? 'Mostrar contraseña' : 'Ocultar contraseña');
        toggle.find('.auth-eye').toggleClass('d-none', !showing);
        toggle.find('.auth-eye-off').toggleClass('d-none', showing);
    });

    function updateCapsHint(event) {
        var original = event.originalEvent;
        var active = !!(original && original.getModifierState && original.getModifierState('CapsLock'));
        $('#caps-hint').toggleClass('d-none', !active);
    }
    password.on('keydown keyup', updateCapsHint);

    $('#auth-help-toggle').on('click', function () {
        var button = $(this);
        var expanded = button.attr('aria-expanded') === 'true';
        button.attr('aria-expanded', expanded ? 'false' : 'true');
        $('#auth-help').toggleClass('d-none', expanded);
    });

    form.on('submit', function (event) {
        event.preventDefault();
        var alertBox = $('#form-alert');

        if (!validateIdentifier(true)) {
            identifier.trigger('focus');
            return;
        }
        if (!this.checkValidity()) {
            this.reportValidity();
            return;
        }

        alertBox.addClass('d-none').text('');
        FacturaSV.showFieldErrors(form, {});
        FacturaSV.setLoading(form, true);

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
            alertBox.text(response.message || 'No fue posible iniciar sesión. Inténtelo nuevamente.').removeClass('d-none');
            FacturaSV.showFieldErrors(form, response.errors || {});
            FacturaSV.setLoading(form, false);
            var card = $('.auth-card');
            card.removeClass('is-shaking');
            window.requestAnimationFrame(function () {
                card.addClass('is-shaking');
            });
            card.one('animationend', function () { card.removeClass('is-shaking'); });
            if (response.errors && response.errors.password) {
                password.trigger('focus');
            }
        });
    });
})(jQuery);
