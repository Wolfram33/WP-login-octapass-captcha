// admin-preview.js
// Steuert die Vorschauform (Kreis/Quadrat) im Adminbereich

document.addEventListener('DOMContentLoaded', function() {
    var select = document.getElementById('octapass-shape-select');
    if (!select) return;
    var updateShapes = function() {
        var val = select.value;
        document.querySelectorAll('.octapass-shape-preview').forEach(function(el) {
            el.style.borderRadius = (val === 'circle') ? '50%' : '0';
        });
    };
    select.addEventListener('change', updateShapes);
    updateShapes();
});
