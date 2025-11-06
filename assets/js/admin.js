/**
 * WP Versions Themes & Plugins - Admin JavaScript
 *
 * Actualizado para soporte de GitHub Apps con Installation Tokens
 *
 * @package WP_Versions_Plugins_Themes
 * @since 1.5.0
 */

(function ($) {
  "use strict";

  // Variables globales
  let currentOrganizations = [];
  let currentRepositories = [];
  let currentBranches = [];
  let selectedRepo = null;

  // DEBUG MODE - Cambiar a false en producción
  const DEBUG = typeof wpvtp_ajax !== 'undefined' && wpvtp_ajax.debug === '1';

  /**
   * Logging condicional para debug
   */
  function debugLog(message, data = null) {
    if (DEBUG) {
      console.log('[WPVTP Debug]', message, data || '');
    }
  }

  /**
   * Inicialización cuando el documento está listo
   */
  $(document).ready(function () {
    debugLog('Inicializando plugin...');
    
    initializeWizard();
    initializeActions();
    initializeTokenValidation();

    // Cargar organizaciones automáticamente si estamos en la página de instalación
    if ($("#wpvtp-organization").length) {
      loadOrganizations();
    }

    // Manejar formulario de OAuth (si existe)
    $("#wpvtp-oauth-form").on("submit", function (e) {
      const clientId = $("#client_id").val().trim();
      const clientSecret = $("#client_secret").val().trim();

      if (!clientId || !clientSecret) {
        e.preventDefault();
        showNotification(
          "❌ Client ID y Client Secret son requeridos",
          "error"
        );
        return false;
      }

      if (clientSecret === "••••••••••••••••••••") {
        e.preventDefault();
        showNotification("❌ Debes ingresar un Client Secret válido", "error");
        return false;
      }
    });
  });

  /**
   * Inicializar wizard de instalación
   */
  function initializeWizard() {
    // Cambio de organización
    $("#wpvtp-organization").on("change", function () {
      const selectedOption = $(this).find('option:selected');
      const owner = selectedOption.val();
      const type = selectedOption.data('type');

      debugLog('Organización seleccionada:', { owner, type });

      if (owner) {
        loadRepositories(owner, type);
        showStep("repository");
      } else {
        hideStep("repository");
        hideStep("type");
        hideStep("branch");
        hideStep("custom-name");
        hideStep("confirm");
      }
    });

    // Cambio de repositorio
    $("#wpvtp-repository").on("change", function () {
      const repoData = $(this).val();
      if (repoData) {
        const repo = JSON.parse(repoData);
        selectedRepo = repo;

        debugLog('Repositorio seleccionado:', repo);

        // Mostrar información del repositorio
        showRepositoryInfo(repo);

        // Mostrar paso de tipo (nuevo)
        showStep("type");
        $("#wpvtp-repo-type").prop("disabled", false);
      } else {
        hideStep("type");
        hideStep("branch");
        hideStep("custom-name");
        hideStep("confirm");
        $("#wpvtp-repo-info").hide();
      }
    });

    // Cambio de tipo (NUEVO)
    $("#wpvtp-repo-type").on("change", function () {
      const type = $(this).val();
      if (type && selectedRepo) {
        debugLog('Tipo seleccionado:', type);
        
        // Guardar el tipo en el objeto selectedRepo
        selectedRepo.detected_type = type;
        
        // Cargar ramas
        loadBranches(selectedRepo.owner.login, selectedRepo.name);
        showStep("branch");
      } else {
        hideStep("branch");
        hideStep("custom-name");
        hideStep("confirm");
      }
    });

    // Cambio de rama
    $("#wpvtp-branch").on("change", function () {
      const branch = $(this).val();
      if (branch && selectedRepo) {
        debugLog('Rama seleccionada:', branch);
        
        // Mostrar paso de nombre personalizado
        showStep("custom-name");
        
        // Sugerir nombre por defecto basado en repo y rama
        suggestCustomName();
        
        showInstallSummary();
        showStep("confirm");
      } else {
        hideStep("custom-name");
        hideStep("confirm");
      }
    });

    // Cambio en nombre personalizado - actualizar resumen
    $("#wpvtp-custom-name").on("input", function () {
      if (selectedRepo && $("#wpvtp-branch").val()) {
        showInstallSummary();
      }
    });

    // Envío del formulario de instalación
    $("#wpvtp-install-form").on("submit", function (e) {
      e.preventDefault();
      installRepository();
    });
  }

  /**
   * Sugerir nombre personalizado basado en repositorio y rama
   */
  function suggestCustomName() {
    if (!selectedRepo) return;
    
    const branch = $("#wpvtp-branch").val();
    const repoName = selectedRepo.name;
    
    // Solo sugerir si no es la rama principal y es un tema
    if (selectedRepo.detected_type === 'theme' && 
        branch && 
        !['main', 'master'].includes(branch)) {
      
      const suggestedName = repoName + ' (' + branch.charAt(0).toUpperCase() + branch.slice(1) + ')';
      $("#wpvtp-custom-name").attr('placeholder', 'Ej: ' + suggestedName);
    } else {
      $("#wpvtp-custom-name").attr('placeholder', 'Déjalo vacío para usar el nombre del repositorio');
    }
  }

  /**
   * Inicializar acciones de la tabla de repositorios
   */
  function initializeActions() {
    // Actualizar repositorio
    $(document).on("click", ".wpvtp-update-repo", function (e) {
      e.preventDefault();
      const localPath = $(this).data("path");
      const button = $(this);

      updateRepository(localPath, button);
    });

    // Cambiar rama
    $(document).on("click", ".wpvtp-switch-branch", function (e) {
      e.preventDefault();
      const localPath = $(this).data("path");
      const repoUrl = $(this).data("repo-url");

      showBranchModal(localPath, repoUrl);
    });

    // Eliminar repositorio
    $(document).on("click", ".wpvtp-remove-repo", function (e) {
      e.preventDefault();
      const localPath = $(this).data("path");
      const repoName = $(this).data("name");

      showConfirmModal(
        "Confirmar Eliminación",
        `¿Estás seguro de que quieres eliminar el repositorio "${repoName}"? Esta acción no se puede deshacer.`,
        "Eliminar",
        "button-link-delete",
        function () {
          removeRepository(localPath);
        }
      );
    });
  }

  /**
   * Inicializar validación de token
   */
  function initializeTokenValidation() {
    $("#wpvtp-validate-token").on("click", function (e) {
      e.preventDefault();
      const token = $('input[name="github_token"]').val();

      if (!token || token === "••••••••••••••••") {
        showTokenValidation("Por favor ingresa un token válido", "error");
        return;
      }

      validateToken(token);
    });
  }

  /**
   * Desconectar de GitHub
   */
  window.disconnectGitHub = function() {
    if (
      !confirm(
        "¿Estás seguro de que quieres desconectar de GitHub? Perderás acceso a los repositorios privados."
      )
    ) {
      return;
    }

    debugLog('Desconectando de GitHub...');

    $.post(wpvtp_ajax.ajax_url, {
      action: "wpvtp_disconnect_github",
      nonce: wpvtp_ajax.nonce,
    })
      .done(function (response) {
        if (response.success) {
          showNotification("✅ " + response.data.message, "success");
          setTimeout(() => location.reload(), 1500);
        } else {
          showNotification(
            "❌ Error al desconectar: " + response.data.error,
            "error"
          );
        }
      })
      .fail(function () {
        showNotification("❌ Error de conexión al desconectar", "error");
      });
  };

  /**
   * Cargar organizaciones desde GitHub
   */
  function loadOrganizations() {
    const select = $("#wpvtp-organization");

    debugLog('Cargando organizaciones...');

    select
      .html('<option value="">Cargando organizaciones...</option>')
      .prop("disabled", true);

    $.post(wpvtp_ajax.ajax_url, {
      action: "wpvtp_get_organizations",
      nonce: wpvtp_ajax.nonce,
    })
      .done(function (response) {
        debugLog('Respuesta de organizaciones:', response);
        
        if (response.success) {
          currentOrganizations = response.data;
          populateOrganizationSelect();
        } else {
          select.html(
            '<option value="">Error al cargar organizaciones</option>'
          );
          showNotification(
            "Error al cargar organizaciones: " + response.data,
            "error"
          );

          if (
            response.data.includes("inválido") ||
            response.data.includes("401") ||
            response.data.includes("expirada")
          ) {
            showNotification(
              "❌ Sesión inválida o expirada. Ve a Configuración para reconectar con GitHub.",
              "error"
            );
          }
        }
      })
      .fail(function () {
        select.html('<option value="">Error de conexión</option>');
        showNotification("Error de conexión al cargar organizaciones", "error");
      })
      .always(function () {
        select.prop("disabled", false);
      });
  }

  /**
   * Poblar select de organizaciones
   */
  function populateOrganizationSelect() {
    const select = $("#wpvtp-organization");
    let html = '<option value="">Selecciona una organización o usuario...</option>';

    currentOrganizations.forEach(function (org) {
      const type = org.type === 'Organization' ? 'org' : 'user';
      const icon = org.type === 'Organization' ? '🏢' : '👤';
      const label = org.login + (org.description ? ' - ' + org.description : '');
      
      html += `<option value="${org.login}" data-type="${type}">${icon} ${label}</option>`;
    });

    select.html(html);
    debugLog(`${currentOrganizations.length} organizaciones cargadas`);
  }

  /**
   * Cargar repositorios de una organización
   */
  function loadRepositories(owner, type) {
    const select = $("#wpvtp-repository");

    debugLog('Cargando repositorios de:', { owner, type });

    select
      .html('<option value="">Cargando repositorios...</option>')
      .prop("disabled", true);

    $.post(wpvtp_ajax.ajax_url, {
      action: "wpvtp_get_repositories",
      owner: owner,
      type: type,
      nonce: wpvtp_ajax.nonce,
    })
      .done(function (response) {
        debugLog('Respuesta de repositorios:', response);
        
        if (response.success) {
          currentRepositories = response.data;
          populateRepositorySelect();
          
          // Contar repos privados
          const privateCount = currentRepositories.filter(r => r.private).length;
          if (privateCount > 0) {
            debugLog(`✅ ${privateCount} repositorio(s) privado(s) encontrado(s)`);
          }
        } else {
          select.html('<option value="">Error al cargar repositorios</option>');
          showNotification(
            "Error al cargar repositorios: " + response.data,
            "error"
          );
        }
      })
      .fail(function () {
        select.html('<option value="">Error de conexión</option>');
        showNotification("Error de conexión al cargar repositorios", "error");
      })
      .always(function () {
        select.prop("disabled", false);
      });
  }

  /**
   * Poblar select de repositorios
   * ACTUALIZADO: Ahora muestra indicador visual para repos privados
   */
  function populateRepositorySelect() {
    const select = $("#wpvtp-repository");
    
    if (currentRepositories.length === 0) {
      select.html('<option value="">No hay repositorios disponibles</option>');
      return;
    }

    let html = '<option value="">Selecciona un repositorio...</option>';

    // Ordenar: privados primero, luego por nombre
    const sortedRepos = currentRepositories.sort((a, b) => {
      if (a.private === b.private) {
        return a.name.localeCompare(b.name);
      }
      return a.private ? -1 : 1;
    });

    sortedRepos.forEach(function (repo) {
      // Indicador visual para repos privados
      const privacyIcon = repo.private ? '🔒 ' : '🌐 ';
      const repoData = JSON.stringify(repo);
      const description = repo.description ? ' - ' + repo.description.substring(0, 50) : '';
      
      html += `<option value='${repoData}'>${privacyIcon}${repo.name}${description}</option>`;
    });

    select.html(html);
    
    // Mostrar mensaje si hay repos privados
    const privateCount = currentRepositories.filter(r => r.private).length;
    if (privateCount > 0) {
      showNotification(
        `✅ ${privateCount} repositorio(s) privado(s) disponible(s)`,
        "success"
      );
    }
    
    debugLog(`${currentRepositories.length} repositorios cargados (${privateCount} privados)`);
  }

  /**
   * Mostrar información del repositorio seleccionado
   */
  function showRepositoryInfo(repo) {
    const privacyBadge = repo.private 
      ? '<span style="background: #d63638; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px; margin-left: 8px;">🔒 PRIVADO</span>'
      : '<span style="background: #00a32a; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px; margin-left: 8px;">🌐 PÚBLICO</span>';
    
    const languageBadge = repo.language 
      ? `<span style="background: #f0f0f0; padding: 3px 8px; border-radius: 3px; font-size: 11px; margin-left: 8px;">${repo.language}</span>`
      : '';

    $("#repo-description").html(
      (repo.description || "Sin descripción") + privacyBadge + languageBadge
    );

    $("#wpvtp-repo-info").show().addClass("wpvtp-fade-in");
  }

  /**
   * Detectar tipo de repositorio (theme/plugin)
   * DEPRECATED: Ahora se hace de forma manual por el usuario
   */
  function detectRepoType(repo) {
    // Esta función ya no se usa
    // El tipo ahora se selecciona manualmente en el paso 3 del wizard
    /*
    let detectedType = "desconocido";
    const repoName = repo.name.toLowerCase();

    // Detectar por nombre
    if (repoName.includes("theme") || repoName.includes("tema")) {
      detectedType = "theme";
    } else if (repoName.includes("plugin")) {
      detectedType = "plugin";
    }

    // Agregar al objeto repo
    repo.detected_type = detectedType;

    const typeLabel = detectedType === "theme" ? "🎨 Tema de WordPress" 
                    : detectedType === "plugin" ? "🔌 Plugin de WordPress"
                    : "❓ No detectado";

    $("#repo-type").html(typeLabel);
    */
  }

  /**
   * Cargar ramas de un repositorio
   */
  function loadBranches(owner, repo) {
    const select = $("#wpvtp-branch");

    debugLog('Cargando ramas de:', { owner, repo });

    select
      .html('<option value="">Cargando ramas...</option>')
      .prop("disabled", true);

    $.post(wpvtp_ajax.ajax_url, {
      action: "wpvtp_get_branches",
      owner: owner,
      repo: repo,
      nonce: wpvtp_ajax.nonce,
    })
      .done(function (response) {
        debugLog('Respuesta de ramas:', response);
        
        if (response.success) {
          currentBranches = response.data;
          populateBranchSelect();
        } else {
          select.html('<option value="">Error al cargar ramas</option>');
          showNotification("Error al cargar ramas: " + response.data, "error");
        }
      })
      .fail(function () {
        select.html('<option value="">Error de conexión</option>');
        showNotification("Error de conexión al cargar ramas", "error");
      })
      .always(function () {
        select.prop("disabled", false);
      });
  }

  /**
   * Poblar select de ramas
   */
  function populateBranchSelect() {
    const select = $("#wpvtp-branch");
    
    if (currentBranches.length === 0) {
      select.html('<option value="">No hay ramas disponibles</option>');
      return;
    }

    let html = '<option value="">Selecciona una rama...</option>';

    currentBranches.forEach(function (branch) {
      // Destacar rama principal
      const isMain = branch.name === 'main' || branch.name === 'master';
      const star = isMain ? '⭐ ' : '';
      
      html += `<option value="${branch.name}">${star}${branch.name}</option>`;
    });

    select.html(html);
    debugLog(`${currentBranches.length} ramas cargadas`);
  }

  /**
   * Mostrar resumen de instalación
   */
  function showInstallSummary() {
    if (!selectedRepo) return;

    const branch = $("#wpvtp-branch").val();
    const customName = $("#wpvtp-custom-name").val().trim();
    const finalName = customName || selectedRepo.name;
    
    const privacyBadge = selectedRepo.private ? '🔒 Privado' : '🌐 Público';

    const summary = `
      <h4>Resumen de Instalación</h4>
      <p><strong>Repositorio:</strong> ${selectedRepo.full_name} (${privacyBadge})</p>
      <p><strong>Rama:</strong> ${branch}</p>
      <p><strong>Tipo:</strong> ${selectedRepo.detected_type === 'theme' ? '🎨 Tema' : '🔌 Plugin'}</p>
      ${customName ? `<p><strong>Nombre personalizado:</strong> ${customName}</p>` : ''}
      <p><strong>Nombre final:</strong> ${finalName}</p>
    `;

    $("#wpvtp-install-summary").html(summary);
  }

  /**
   * Instalar repositorio
   */
  function installRepository() {
    if (!selectedRepo) return;

    const branch = $("#wpvtp-branch").val();
    const customName = $("#wpvtp-custom-name").val().trim();
    const button = $("#wpvtp-install-form button[type='submit']");
    const resultsDiv = $("#wpvtp-install-results");

    debugLog('Iniciando instalación...', {
      repo: selectedRepo.full_name,
      branch: branch,
      customName: customName
    });

    button.addClass("loading").prop("disabled", true);
    resultsDiv.hide().removeClass("success error");

    $.post(wpvtp_ajax.ajax_url, {
      action: "wpvtp_clone_repository",
      repo_url: selectedRepo.clone_url,
      branch: branch,
      repo_type: selectedRepo.detected_type,
      repo_name: selectedRepo.name,
      custom_name: customName,
      nonce: wpvtp_ajax.nonce,
    })
      .done(function (response) {
        debugLog('Respuesta de instalación:', response);
        
        if (response.success) {
          resultsDiv
            .addClass("success")
            .html(
              `
                <h3>✅ Instalación Exitosa</h3>
                <p>${response.message}</p>
                <p style="margin-top: 15px;">
                  <a href="${wpvtp_ajax.admin_url}admin.php?page=wp-versions-themes-plugins" class="button button-primary">Ver Repositorios</a>
                </p>
              `
            )
            .show()
            .addClass("wpvtp-fade-in");

          // Reset form
          $("#wpvtp-install-form")[0].reset();
          hideAllStepsExceptFirst();
        } else {
          resultsDiv
            .addClass("error")
            .html(
              `
                <h3>❌ Error en la Instalación</h3>
                <p>${response.error}</p>
              `
            )
            .show()
            .addClass("wpvtp-fade-in");
        }
      })
      .fail(function (xhr, status, error) {
        debugLog('Error en instalación:', { xhr, status, error });
        
        resultsDiv
          .addClass("error")
          .html(
            `
              <h3>❌ Error de Conexión</h3>
              <p>No se pudo conectar con el servidor. Por favor intenta nuevamente.</p>
            `
          )
          .show()
          .addClass("wpvtp-fade-in");
      })
      .always(function () {
        button.removeClass("loading").prop("disabled", false);
      });
  }

  /**
   * Actualizar repositorio
   */
  function updateRepository(localPath, button) {
    const originalText = button.text();
    button.addClass("loading").prop("disabled", true);

    debugLog('Actualizando repositorio:', localPath);

    $.post(wpvtp_ajax.ajax_url, {
      action: "wpvtp_update_repository",
      local_path: localPath,
      nonce: wpvtp_ajax.nonce,
    })
      .done(function (response) {
        if (response.success) {
          showNotification("✅ " + response.message, "success");
          setTimeout(() => location.reload(), 1500);
        } else {
          showNotification("❌ " + response.error, "error");
        }
      })
      .fail(function () {
        showNotification(
          "❌ Error de conexión al actualizar repositorio",
          "error"
        );
      })
      .always(function () {
        button.removeClass("loading").prop("disabled", false);
      });
  }

  /**
   * Eliminar repositorio
   */
  function removeRepository(localPath) {
    debugLog('Eliminando repositorio:', localPath);
    
    $.post(wpvtp_ajax.ajax_url, {
      action: "wpvtp_remove_repository",
      local_path: localPath,
      nonce: wpvtp_ajax.nonce,
    })
      .done(function (response) {
        if (response.success) {
          showNotification("✅ " + response.message, "success");
          setTimeout(() => location.reload(), 1500);
        } else {
          showNotification("❌ " + response.error, "error");
        }
      })
      .fail(function () {
        showNotification(
          "❌ Error de conexión al eliminar repositorio",
          "error"
        );
      });
  }

  /**
   * Mostrar modal para cambiar rama
   */
  function showBranchModal(localPath, repoUrl) {
    const urlParts = repoUrl
      .replace("https://github.com/", "")
      .replace(".git", "")
      .split("/");
    const owner = urlParts[0];
    const repo = urlParts[1];

    showModal(
      "Cambiar Rama",
      `
        <p>Selecciona la nueva rama para este repositorio:</p>
        <select id="branch-select" style="width: 100%; margin-bottom: 15px;">
          <option value="">Cargando ramas...</option>
        </select>
        <div style="text-align: right;">
          <button type="button" class="button" onclick="closeModal()">Cancelar</button>
          <button type="button" class="button button-primary" id="confirm-branch-switch" disabled>Cambiar Rama</button>
        </div>
      `,
      function () {
        loadBranchesForModal(owner, repo, localPath);
      }
    );
  }

  /**
   * Cargar ramas para el modal
   */
  function loadBranchesForModal(owner, repo, localPath) {
    const select = $("#branch-select");

    $.post(wpvtp_ajax.ajax_url, {
      action: "wpvtp_get_branches",
      owner: owner,
      repo: repo,
      nonce: wpvtp_ajax.nonce,
    })
      .done(function (response) {
        if (response.success) {
          let html = '<option value="">Selecciona una rama...</option>';
          response.data.forEach(function (branch) {
            const star = (branch.name === 'main' || branch.name === 'master') ? '⭐ ' : '';
            html += `<option value="${branch.name}">${star}${branch.name}</option>`;
          });
          select.html(html);

          select.on("change", function () {
            $("#confirm-branch-switch").prop("disabled", !$(this).val());
          });

          $("#confirm-branch-switch").on("click", function () {
            const newBranch = select.val();
            if (newBranch) {
              switchBranch(localPath, newBranch);
              closeModal();
            }
          });
        } else {
          select.html('<option value="">Error al cargar ramas</option>');
        }
      })
      .fail(function () {
        select.html('<option value="">Error de conexión</option>');
      });
  }

  /**
   * Cambiar rama de un repositorio
   */
  function switchBranch(localPath, newBranch) {
    debugLog('Cambiando rama:', { localPath, newBranch });
    
    $.post(wpvtp_ajax.ajax_url, {
      action: "wpvtp_switch_branch",
      local_path: localPath,
      new_branch: newBranch,
      nonce: wpvtp_ajax.nonce,
    })
      .done(function (response) {
        if (response.success) {
          showNotification("✅ " + response.message, "success");
          setTimeout(() => location.reload(), 1500);
        } else {
          showNotification("❌ " + response.error, "error");
        }
      })
      .fail(function () {
        showNotification("❌ Error de conexión al cambiar rama", "error");
      });
  }

  /**
   * Utilidades de UI
   */
  function showStep(stepName) {
    $(`#step-${stepName}`).show().addClass("wpvtp-fade-in");
  }

  function hideStep(stepName) {
    $(`#step-${stepName}`).hide().removeClass("wpvtp-fade-in");
  }

  function hideAllStepsExceptFirst() {
    hideStep("repository");
    hideStep("type");
    hideStep("branch");
    hideStep("custom-name");
    hideStep("confirm");
    $("#wpvtp-repo-info").hide();
  }

  function showNotification(message, type = "info") {
    $(".wpvtp-notification").remove();

    const notification = $(`
      <div class="wpvtp-notification notice notice-${type} is-dismissible" style="position: fixed; top: 32px; right: 20px; z-index: 100000; max-width: 400px;">
        <p>${message}</p>
        <button type="button" class="notice-dismiss">
          <span class="screen-reader-text">Cerrar esta notificación.</span>
        </button>
      </div>
    `);

    $("body").append(notification);

    setTimeout(() => {
      notification.fadeOut(() => notification.remove());
    }, 5000);

    notification.find(".notice-dismiss").on("click", function () {
      notification.fadeOut(() => notification.remove());
    });
  }

  function showModal(title, content, onOpen = null) {
    const modal = $(`
      <div class="wpvtp-modal">
        <div class="wpvtp-modal-content">
          <div class="wpvtp-modal-header">
            <h3>${title}</h3>
            <button type="button" class="wpvtp-modal-close" onclick="closeModal()">&times;</button>
          </div>
          <div class="wpvtp-modal-body">
            ${content}
          </div>
        </div>
      </div>
    `);

    $("body").append(modal);

    setTimeout(() => {
      modal.addClass("wpvtp-modal-visible");
    }, 10);

    $(document).on("keyup.wpvtp-modal", function (e) {
      if (e.keyCode === 27) {
        closeModal();
      }
    });

    modal.on("click", function (e) {
      if (e.target === this) {
        closeModal();
      }
    });

    if (onOpen) {
      onOpen();
    }
  }

  function showConfirmModal(
    title,
    message,
    confirmText = "Confirmar",
    confirmClass = "button-primary",
    onConfirm = null
  ) {
    showModal(
      title,
      `
        <p>${message}</p>
        <div style="text-align: right; margin-top: 20px;">
          <button type="button" class="button" onclick="closeModal()">Cancelar</button>
          <button type="button" class="button ${confirmClass}" id="confirm-action" style="margin-left: 10px;">${confirmText}</button>
        </div>
      `
    );

    $("#confirm-action").on("click", function () {
      if (onConfirm) {
        onConfirm();
      }
      closeModal();
    });
  }

  window.closeModal = function () {
    const modal = $(".wpvtp-modal");
    modal.removeClass("wpvtp-modal-visible");

    setTimeout(() => {
      modal.remove();
      $(document).off("keyup.wpvtp-modal");
    }, 300);
  };

})(jQuery);