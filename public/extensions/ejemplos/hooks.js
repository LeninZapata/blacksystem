class ejemplosHooks {
  
  static hook_hooksCaso1() {
    return [
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      // HOOK BEFORE VIEW - Banner superior
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      {
        id: 'hook-antes-tabs',
        type: 'html',
        order: 1,
        context: 'view',
        position: 'before',
        content: `
          <div style="padding:1.5rem;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;border-radius:8px;margin-bottom:1rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);">
            <h2 style="margin:0 0 0.5rem 0;display:flex;align-items:center;gap:0.5rem;">
              <span style="font-size:1.5rem;">🎯</span>
              <span>Hook ANTES de la Vista</span>
            </h2>
            <p style="margin:0;opacity:0.9;">
              Este hook se muestra <strong>antes de los tabs</strong> usando <code style="background:rgba(255,255,255,0.2);padding:0.2rem 0.4rem;border-radius:4px;">context: 'view', position: 'before'</code>
            </p>
          </div>
        `
      },
      
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      // HOOKS DENTRO DE TAB 1 - Mezclados con content
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      {
        id: 'hook-tab1-inicio',
        type: 'html',
        order: 5,
        context: 'tab',
        target: 'tab1',
        content: `
          <div style="padding:1rem;background:#dcfce7;border-radius:6px;margin:0.5rem 0;border-left:4px solid #22c55e;">
            <strong>🎯 Hook Tab 1 - Order 5 (INICIO)</strong>
            <p style="margin:0.5rem 0 0 0;">Este hook aparece al <strong>inicio</strong> porque tiene order: 5 (menor que el contenido).</p>
          </div>
        `
      },
      {
        id: 'hook-tab1-medio',
        type: 'html',
        order: 15,
        context: 'tab',
        target: 'tab1',
        content: `
          <div style="padding:1rem;background:#fef9c3;border-radius:6px;margin:0.5rem 0;border-left:4px solid #eab308;">
            <strong>🎯 Hook Tab 1 - Order 15 (MEDIO)</strong>
            <p style="margin:0.5rem 0 0 0;">Este hook aparece en <strong>medio</strong> porque tiene order: 15 (entre 10 y 20).</p>
          </div>
        `
      },
      {
        id: 'hook-tab1-final',
        type: 'html',
        order: 25,
        context: 'tab',
        target: 'tab1',
        content: `
          <div style="padding:1rem;background:#dbeafe;border-radius:6px;margin:0.5rem 0;border-left:4px solid #3b82f6;">
            <strong>🎯 Hook Tab 1 - Order 25 (FINAL)</strong>
            <p style="margin:0.5rem 0 0 0;">Este hook aparece al <strong>final</strong> porque tiene order: 25 (mayor que todo el contenido).</p>
          </div>
        `
      },
      
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      // HOOK DENTRO DE TAB 2
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      {
        id: 'hook-tab2',
        type: 'html',
        order: 5,
        context: 'tab',
        target: 'tab2',
        content: `
          <div style="padding:1rem;background:#fce7f3;border-radius:6px;margin:0.5rem 0;border-left:4px solid #ec4899;">
            <strong>🎯 Hook Tab 2 - Order 5</strong>
            <p style="margin:0.5rem 0 0 0;">Este hook aparece al inicio del Tab 2.</p>
          </div>
        `
      },
      
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      // HOOKS DENTRO DE TAB 4 - COMPONENTE WIDGET
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      {
        id: 'hook-widget-inicio',
        type: 'component',
        component: 'widget',
        order: 8,
        context: 'tab',
        target: 'tab4',
        config: {
          columns: 2,
          widgets: [
            {
              title: '🎯 Hook Widget - Order 8',
              order: 1,
              html: `
                <div style="padding:1.5rem;background:#fef3c7;border:2px dashed #f59e0b;border-radius:6px;">
                  <p style="margin:0 0 1rem 0;color:#92400e;">
                    <strong>Este es un HOOK inyectado</strong> que aparece <strong>ANTES del Widget 1</strong> porque tiene order: 8 (menor que 10).
                  </p>
                  <div style="background:#fff;padding:1rem;border-radius:4px;border-left:3px solid #f59e0b;">
                    <p style="margin:0;font-size:0.9rem;color:#666;">
                      💡 Demuestra cómo inyectar un widget completo antes de widgets existentes usando hooks.
                    </p>
                  </div>
                </div>
              `
            }
          ]
        }
      },
      {
        id: 'hook-widget-medio',
        type: 'component',
        component: 'widget',
        order: 20,
        context: 'tab',
        target: 'tab4',
        config: {
          columns: 2,
          widgets: [
            {
              title: '🎯 Hook Widget - Order 20',
              order: 1,
              html: `
                <div style="padding:1.5rem;background:#e0e7ff;border:2px dashed #6366f1;border-radius:6px;">
                  <p style="margin:0 0 1rem 0;color:#3730a3;">
                    <strong>Este es un HOOK inyectado</strong> que aparece <strong>ENTRE Widget 1 y Widget 2</strong> porque tiene order: 20 (entre 10 y 30).
                  </p>
                  <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;margin-top:1rem;">
                    <div style="text-align:center;padding:1rem;background:white;border-radius:6px;border:2px solid #6366f1;">
                      <div style="font-size:1.5rem;">⚡</div>
                      <div style="font-size:1.2rem;font-weight:bold;color:#4f46e5;margin-top:0.5rem;">98%</div>
                      <div style="color:#64748b;font-size:0.85rem;margin-top:0.25rem;">Performance</div>
                    </div>
                    <div style="text-align:center;padding:1rem;background:white;border-radius:6px;border:2px solid #6366f1;">
                      <div style="font-size:1.5rem;">🚀</div>
                      <div style="font-size:1.2rem;font-weight:bold;color:#4f46e5;margin-top:0.5rem;">24/7</div>
                      <div style="color:#64748b;font-size:0.85rem;margin-top:0.25rem;">Uptime</div>
                    </div>
                  </div>
                </div>
              `
            }
          ]
        }
      },
      {
        id: 'hook-widget-final',
        type: 'component',
        component: 'widget',
        order: 40,
        context: 'tab',
        target: 'tab4',
        config: {
          columns: 2,
          widgets: [
            {
              title: '🎯 Hook Widget - Order 40',
              order: 1,
              html: `
                <div style="padding:1.5rem;background:#fce7f3;border:2px dashed #ec4899;border-radius:6px;">
                  <p style="margin:0 0 1rem 0;color:#9f1239;">
                    <strong>Este es un HOOK inyectado</strong> que aparece <strong>DESPUÉS del Widget 2</strong> porque tiene order: 40 (mayor que 30).
                  </p>
                  <div style="background:white;padding:1rem;border-radius:6px;border-left:3px solid #ec4899;">
                    <h4 style="margin:0 0 0.75rem 0;color:#be185d;">📈 Resumen del Orden Final</h4>
                    <ul style="margin:0;padding-left:1.5rem;color:#64748b;font-size:0.9rem;">
                      <li>Info (Order 5)</li>
                      <li><strong style="color:#f59e0b;">Hook Order 8</strong> ← Inyectado</li>
                      <li>Widget 1 (Order 10)</li>
                      <li><strong style="color:#6366f1;">Hook Order 20</strong> ← Inyectado</li>
                      <li>Widget 2 (Order 30)</li>
                      <li><strong style="color:#ec4899;">Hook Order 40</strong> ← Inyectado (este)</li>
                    </ul>
                  </div>
                </div>
              `
            }
          ]
        }
      },
      
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      // HOOK AFTER VIEW - Banner inferior
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      {
        id: 'hook-despues-tabs',
        type: 'html',
        order: 999,
        context: 'view',
        position: 'after',
        content: `
          <div style="padding:1.5rem;background:linear-gradient(135deg,#f093fb 0%,#f5576c 100%);color:white;border-radius:8px;margin-top:1rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);">
            <h2 style="margin:0 0 0.5rem 0;display:flex;align-items:center;gap:0.5rem;">
              <span style="font-size:1.5rem;">🎯</span>
              <span>Hook DESPUÉS de la Vista</span>
            </h2>
            <p style="margin:0;opacity:0.9;">
              Este hook se muestra <strong>después de los tabs</strong> usando <code style="background:rgba(255,255,255,0.2);padding:0.2rem 0.4rem;border-radius:4px;">context: 'view', position: 'after'</code>
            </p>
          </div>
        `
      }
    ];
  }

  static hook_hooksCaso2() {
    return [
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      // HOOKS EN CONTENT - Mezclados con items de content
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      {
        id: 'hook-content-inicio',
        type: 'html',
        order: 7,
        context: 'content',
        content: `
          <div style="padding:1rem;background:#dcfce7;border-radius:6px;margin:0.5rem 0;border-left:4px solid #22c55e;">
            <strong>🎯 Hook Content - Order 7 (ANTES del primer contenido)</strong>
            <p style="margin:0.5rem 0 0 0;">Este hook aparece antes del contenido porque tiene order: 7 (menor que 10).</p>
          </div>
        `
      },
      {
        id: 'hook-content-medio',
        type: 'html',
        order: 15,
        context: 'content',
        content: `
          <div style="padding:1rem;background:#fef9c3;border-radius:6px;margin:0.5rem 0;border-left:4px solid #eab308;">
            <strong>🎯 Hook Content - Order 15 (ENTRE contenido 1 y 2)</strong>
            <p style="margin:0.5rem 0 0 0;">Este hook aparece en medio porque tiene order: 15 (entre 10 y 20).</p>
          </div>
        `
      },
      {
        id: 'hook-content-medio2',
        type: 'html',
        order: 25,
        context: 'content',
        content: `
          <div style="padding:1rem;background:#fce7f3;border-radius:6px;margin:0.5rem 0;border-left:4px solid #ec4899;">
            <strong>🎯 Hook Content - Order 25 (ENTRE contenido 2 y 3)</strong>
            <p style="margin:0.5rem 0 0 0;">Este hook aparece después del segundo contenido porque tiene order: 25 (entre 20 y 30).</p>
          </div>
        `
      },
      {
        id: 'hook-content-final',
        type: 'html',
        order: 35,
        context: 'content',
        content: `
          <div style="padding:1rem;background:#dbeafe;border-radius:6px;margin:0.5rem 0;border-left:4px solid #3b82f6;">
            <strong>🎯 Hook Content - Order 35 (DESPUÉS del último contenido)</strong>
            <p style="margin:0.5rem 0 0 0;">Este hook aparece al final porque tiene order: 35 (mayor que 30).</p>
          </div>
        `
      },
      
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      // HOOK ANTES DEL FORMULARIO
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      {
        id: 'hook-form-info',
        type: 'html',
        order: 105,
        context: 'content',
        content: `
          <div style="padding:1.5rem;background:#e0e7ff;border:2px solid #6366f1;border-radius:8px;margin:1rem 0;">
            <h4 style="margin:0 0 1rem 0;color:#4f46e5;display:flex;align-items:center;gap:0.5rem;">
              <span style="font-size:1.5rem;">💡</span>
              <span>Hook Inyectado ANTES del Formulario (Order 105)</span>
            </h4>
            <p style="margin:0 0 0.5rem 0;color:#3730a3;">
              Este hook aparece justo antes del formulario porque tiene order: 105 (el formulario tiene order: 110).
            </p>
            <div style="background:white;padding:1rem;border-radius:6px;margin-top:1rem;">
              <p style="margin:0;font-size:0.9rem;color:#666;">
                <strong>📝 Nota:</strong> El formulario también tiene hooks <strong>DENTRO</strong> que inyectan fields adicionales usando <code>context: 'form'</code>.
              </p>
            </div>
          </div>
        `
      },
      
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      // HOOK DESPUÉS DEL FORMULARIO
      // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
      {
        id: 'hook-form-summary',
        type: 'html',
        order: 115,
        context: 'content',
        content: `
          <div style="padding:1.5rem;background:#fce7f3;border:2px solid #ec4899;border-radius:8px;margin:1rem 0;">
            <h4 style="margin:0 0 1rem 0;color:#be185d;display:flex;align-items:center;gap:0.5rem;">
              <span style="font-size:1.5rem;">📊</span>
              <span>Hook Inyectado DESPUÉS del Formulario (Order 115)</span>
            </h4>
            <p style="margin:0 0 1rem 0;color:#9f1239;">
              Este hook aparece justo después del formulario porque tiene order: 115 (el formulario tiene order: 110).
            </p>
            <div style="background:white;padding:1rem;border-radius:6px;">
              <h5 style="margin:0 0 0.75rem 0;color:#be185d;">🎯 Resumen Completo:</h5>
              <ul style="margin:0;padding-left:1.5rem;color:#64748b;font-size:0.9rem;">
                <li><strong>Context 'content':</strong> 6 hooks alrededor del formulario</li>
                <li><strong>Context 'form':</strong> 4 hooks DENTRO del formulario (fields)</li>
                <li><strong>Total:</strong> 10 hooks demostrados en este caso</li>
              </ul>
            </div>
          </div>
        `
      }
    ];
  }

  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  // HOOKS PARA FORMULARIO - Context 'form'
  // Se inyectan DENTRO del formulario como fields adicionales
  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  static hook_inputs_demo() {
    return [
      // Hook ANTES del email (entre text y email)
      {
        name: 'username',
        label: '🎯 Username (Hook - Order 7)',
        type: 'text',
        placeholder: 'Tu nombre de usuario',
        order: 7,
        context: 'form',
        class: 'hook-field',
        style: 'border: 2px dashed #f59e0b !important; background: #fef3c7 !important; padding: 0.75rem !important;'
      },
      
      // Hook ENTRE password y number
      {
        name: 'confirm_password',
        label: '🎯 Confirmar Password (Hook - Order 17)',
        type: 'password',
        placeholder: 'Repite tu contraseña',
        order: 17,
        context: 'form',
        class: 'hook-field',
        style: 'border: 2px dashed #6366f1 !important; background: #e0e7ff !important; padding: 0.75rem !important;'
      },
      
      // Hook ENTRE select y textarea
      {
        name: 'country',
        label: '🎯 País (Hook - Order 32)',
        type: 'select',
        options: [
          { value: '', label: 'Selecciona un país' },
          { value: 'ec', label: 'Ecuador' },
          { value: 'pe', label: 'Perú' },
          { value: 'co', label: 'Colombia' },
          { value: 'ar', label: 'Argentina' },
          { value: 'mx', label: 'México' }
        ],
        order: 32,
        context: 'form',
        class: 'hook-field',
        style: 'border: 2px dashed #ec4899 !important; background: #fce7f3 !important; padding: 0.75rem !important;'
      },
      
      // Hook AL FINAL (después de checkbox)
      {
        name: 'newsletter',
        label: '🎯 Suscribirse al Newsletter (Hook - Order 45)',
        type: 'checkbox',
        order: 45,
        context: 'form',
        class: 'hook-field'
      }
    ];
  }
}

if (typeof window !== 'undefined') {
  window.ejemplosHooks = ejemplosHooks;
}