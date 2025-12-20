jQuery(document).ready(function($) {
    var octapassContainer = $('#octapass-container');
    var loginForm = $('#loginform');
    var usernameField = $('#user_login'); // Referenz auf das Benutzername-Feld
    var passwordField = $('#user_pass');   // Referenz auf das Passwortfeld
    var submitButton = $('#wp-submit');    // Referenz auf den Anmelden-Button
    var loginMessage = $('#login_error, .message'); // WordPress Login-Meldungen

    // Funktion zum Aktivieren/Deaktivieren der Login-Formularfelder
    function setLoginFormEnabled(enable) {
        usernameField.prop('disabled', !enable).prop('readonly', !enable);
        passwordField.prop('disabled', !enable).prop('readonly', !enable);
        submitButton.prop('disabled', !enable);

        // Optional: Visuelle Anpassungen basierend auf dem Zustand
        if (enable) {
            usernameField.css({'opacity': 1, 'cursor': 'auto'});
            passwordField.css({'opacity': 1, 'cursor': 'auto'});
            submitButton.css({'opacity': 1, 'cursor': 'auto'});
            $('body').removeClass('octapass-captcha-active'); // Entfernt die Body-Klasse
        } else {
            usernameField.css({'opacity': 0.5, 'cursor': 'not-allowed'});
            passwordField.css({'opacity': 0.5, 'cursor': 'not-allowed'});
            submitButton.css({'opacity': 0.5, 'cursor': 'not-allowed'});
            $('body').addClass('octapass-captcha-active'); // Fügt die Body-Klasse hinzu
        }
    }

    // Funktion zum Aktualisieren der Captcha-Anzeige
    function updateCaptchaDisplay(task, clicks, activeColors, isSolved) {
        var taskBox = octapassContainer.find('.octapass-task-box');
        var colorButtonsDiv = octapassContainer.find('#color-buttons');
        var totalRequired = 0;
        var totalClicked = 0;

        taskBox.empty(); // Alten Task leeren
        colorButtonsDiv.empty(); // Alte Buttons leeren

        // Task neu rendern
        if (Object.keys(task).length === 0) {
            taskBox.html('<div>Es gab ein Problem beim Generieren der Aufgabe. Bitte wählen Sie im Adminbereich aktive Farben aus.</div>');
            // Bei Fehler sollte das Captcha-Feld sichtbar sein und das Login-Feld unsichtbar
            octapassContainer.show();
            loginForm.hide();
            setLoginFormEnabled(false); // Login-Formular deaktivieren
        } else {
            // Hilfsfunktion: Farbinfos für Swatch holen
            function getColorObjByName(name) {
                for (var i = 0; i < activeColors.length; i++) {
                    if (activeColors[i].name === name) return activeColors[i];
                }
                return null;
            }
            for (var color in task) {
                var requiredCount = task[color];
                var currentClicks = clicks[color] || 0;
                var done = currentClicks >= requiredCount;
                var status = done ? 'Fertig' : (currentClicks + ' / ' + requiredCount);
                var colorObj = getColorObjByName(color);
                var swatch = colorObj ? '<span class="octapass-color-swatch" style="display:inline-block;width:18px;height:18px;border-radius:50%;border:1px solid #888;margin-right:6px;vertical-align:middle;background:' + colorObj.hex + ';"></span>' : '';
                var colorLabel = $('<div>').text(color).html();
                taskBox.append('<div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">' + swatch + '<span style="min-width:60px;display:inline-block;font-weight:bold;">' + colorLabel + '</span>: <span style="font-weight:' + (done ? 'bold' : 'normal') + ';color:' + (done ? '#2e7d32' : '#222') + ';">' + status + '</span></div>');
                totalRequired += requiredCount;
                totalClicked += currentClicks;
            }

            // Farbbuttons immer mit Swatch und Namen rendern (wie initial im PHP)
            activeColors.forEach(function(color) {
                // Automatische Schriftfarbe je nach Hintergrundhelligkeit
                var hex = color.hex.replace('#', '');
                var textColor = '#111';
                if (hex.length === 6) {
                    var r = parseInt(hex.substr(0,2),16);
                    var g = parseInt(hex.substr(2,2),16);
                    var b = parseInt(hex.substr(4,2),16);
                    var brightness = ((r * 299) + (g * 587) + (b * 114)) / 1000;
                    textColor = (brightness < 128) ? '#fff' : '#111';
                }
                var colorLabel = $('<div>').text(color.name).html(); // Escaping
                colorButtonsDiv.append(
                    '<button class="octapass-color-btn" data-color="' + color.name + '" style="background:' + color.hex + ';display:flex;flex-direction:column;align-items:center;justify-content:center;width:60px;height:60px;border-radius:8px;border:2px solid #888;font-size:15px;gap:4px;" title="' + colorLabel + '" aria-label="' + colorLabel + '">' +
                    '<span aria-hidden="true" style="display:block;width:24px;height:24px;border-radius:50%;background:' + color.hex + ';border:1px solid #666;margin-bottom:2px;"></span>' +
                    '<span style="font-size:13px;line-height:1;color:' + textColor + ';font-weight:bold;">' + colorLabel + '</span>' +
                    '</button>'
                );
            });

            // Fortschrittsbalken aktualisieren
            var progress = totalRequired > 0 ? (totalClicked / totalRequired) * 100 : 0;
            octapassContainer.find('.octapass-progress-bar-inner').css('width', Math.round(progress) + '%');

            if (isSolved) {
                octapassContainer.slideUp(300, function() { // Captcha sanft ausblenden
                    loginForm.fadeIn(300, function() { // Login-Formular sanft einblenden
                        setLoginFormEnabled(true); // Login-Formular aktivieren
                        usernameField.focus(); // Fokus auf Benutzername-Feld setzen
                    });
                });
            } else {
                octapassContainer.show();
                loginForm.hide();
                setLoginFormEnabled(false); // Login-Formular deaktivieren
            }
        }
    }

    // Initialer Zustand beim Laden der Seite
    if (octapass_vars.is_solved === 'true') {
        octapassContainer.hide();
        loginForm.show();
        setLoginFormEnabled(true); // Login-Formular aktivieren
    } else {
        octapassContainer.show();
        loginForm.hide(); // Verstecke das Formular standardmäßig
        setLoginFormEnabled(false); // Login-Formular deaktivieren
    }

    // Klick-Handler für Farbbuttons
    $(document).on('click', '.octapass-color-btn', function() {
        var clickedColor = $(this).data('color');

        $.ajax({
            url: octapass_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'octapass_click',
                color: clickedColor
            },
            // Credentials explizit senden für Firefox-Kompatibilität
            xhrFields: {
                withCredentials: true
            },
            success: function(response) {
                if (response.success) {
                    var task = response.data.task || {};
                    var clicks = response.data.clicks || {};
                    var activeColors = response.data.active_colors || [];
                    var isSolved = typeof response.data.is_solved !== 'undefined' ? response.data.is_solved : false;

                    // Wenn die Antwort die Aufgabe nicht enthält (unerwarteter Fehler), Seite neu laden
                    if (!task || Object.keys(task).length === 0) {
                        location.reload();
                        return;
                    }
                    updateCaptchaDisplay(task, clicks, activeColors, isSolved);
                } else {
                    alert(octapass_vars.error_message || 'Fehler beim Verarbeiten des Klicks.');
                }
            },
            error: function() {
                alert('Ein Netzwerkfehler ist aufgetreten. Bitte versuchen Sie es erneut.');
            }
        });
    });

    // Klick-Handler für Reset-Button
    $(document).on('click', '#octapass-reset', function() {
        $.ajax({
            url: octapass_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'octapass_reset'
            },
            // Credentials explizit senden für Firefox-Kompatibilität
            xhrFields: {
                withCredentials: true
            },
            success: function(response) {
                if (response.success) {
                    // Update der Anzeige mit der neuen Aufgabe
                    updateCaptchaDisplay(response.data.new_task, {}, response.data.active_colors, response.data.is_solved);
                    setLoginFormEnabled(false); // Sicherstellen, dass das Login-Formular deaktiviert ist
                    octapassContainer.show(); // Captcha-Container anzeigen, falls er ausgeblendet war
                } else {
                    alert('Fehler beim Zurücksetzen des Captchas.');
                }
            },
            error: function() {
                alert('Ein Netzwerkfehler ist aufgetreten. Bitte versuchen Sie es erneut.');
            }
        });
    });
});