/**
 * WP Versions Themes & Plugins - Admin JavaScript
 *
 * Updated for GitHub Apps with Installation Tokens support
 *
 * @package WP_Versions_Plugins_Themes
 * @since 1.7.0
 */

(function ($) {
  "use strict";

  // Global variables
  let currentOrganizations = [];
  let currentRepositories = [];
  let currentBranches = [];
  let selectedRepo = null;

  // DEBUG MODE - Change to false in production
  const DEBUG = typeof wpvtp_ajax !== 'undefined' && wpvtp_ajax.debug === '1';

  /**
   * Conditional logging for debug
   */
  function debugLog(message, data = null) {
    if (DEBUG) {
      console.log('[WPVTP Debug]', message, data || '');
    }
  }

  /**
   * Initialization when the document is ready
   */
  $(document).ready(function () {
    debugLog('Initializing plugin...');
    
    initializeWizard();
    initializeActions();
    initializeTokenValidation();
    
    $('#wpvtp-download-wp-content').on('click', function(e) {
        e.preventDefault();
        
        const defaultName = 'wp-content-backup-' + new Date().toISOString().split('T')[0];
        const zipName = prompt('File name for the zip export without ext:', defaultName);
        
        if (!zipName) {
            return; // Usuario canceló
        }

        const button = $(this);
        const originalText = button.text();
        
        button.text('Creanting ZIP...').prop('disabled', true);

        $.post(wpvtp_ajax.ajax_url, {
            action: 'wpvtp_download_wp_content',
            zip_name: zipName,
            nonce: wpvtp_ajax.nonce
        })
        .done(function(response) {
            if (response.success) {
                showNotification('✅ ZIP created' + response.data.filename + ' (' + response.data.size + ')', 'success');
                
                // Descargar archivo automáticamente
                window.location.href = response.data.download_url;
            } else {
                showNotification('❌ Error: ' + response.data, 'error');
            }
        })
        .fail(function() {
            showNotification('❌ Error on creating zip', 'error');
        })
        .always(function() {
            button.text(originalText).prop('disabled', false);
        });
    });
    
    // Reintentar commit
    $(document).on('click', '.wpvtp-retry-commit', function() {
        const button = $(this);
        const commitId = button.data('id');
        const originalText = button.text();
        
        button.text('Procesando...').prop('disabled', true);
        
        $.post(wpvtp_ajax.ajax_url, {
            action: 'wpvtp_retry_commit',
            commit_id: commitId,
            nonce: wpvtp_ajax.nonce
        })
        .done(function(response) {
            if (response.success) {
                showNotification('✅ ' + response.message, 'success');
                location.reload();
            } else {
                showNotification('❌ ' + response.error, 'error');
                button.text(originalText).prop('disabled', false);
            }
        })
        .fail(function() {
            showNotification('❌ Error de conexión', 'error');
            button.text(originalText).prop('disabled', false);
        });
    });
    
    // Eliminar commit
    $(document).on('click', '.wpvtp-delete-commit', function() {
        if (!confirm('¿Eliminar este commit de la cola?')) return;
        
        const button = $(this);
        const commitId = button.data('id');
        
        $.post(wpvtp_ajax.ajax_url, {
            action: 'wpvtp_delete_commit',
            commit_id: commitId,
            nonce: wpvtp_ajax.nonce
        })
        .done(function(response) {
            if (response.success) {
                showNotification('✅ Commit eliminado', 'success');
                location.reload();
            } else {
                showNotification('❌ Error al eliminar', 'error');
            }
        });
    });

    // Load organizations automatically if we are on the installation page
    if ($("#wpvtp-organization").length) {
      loadOrganizations();
    }

    // Handle OAuth form (if it exists)
    $("#wpvtp-oauth-form").on("submit", function (e) {
      const clientId = $("#client_id").val().trim();
      const clientSecret = $("#client_secret").val().trim();

      if (!clientId || !clientSecret) {
        e.preventDefault();
        showNotification(
          "❌ Client ID and Client Secret are required",
          "error"
        );
        return false;
      }

      if (clientSecret === "••••••••••••••••••••") {
        e.preventDefault();
        showNotification("❌ You must enter a valid Client Secret", "error");
        return false;
      }
    });
  });

  /**
   * Initialize installation wizard
   */
  function initializeWizard() {
    // Organization change
    $("#wpvtp-organization").on("change", function () {
      const selectedOption = $(this).find('option:selected');
      const owner = selectedOption.val();
      const type = selectedOption.data('type');

      debugLog('Selected Organization:', { owner, type });

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

    // Repository change
    $("#wpvtp-repository").on("change", function () {
      const repoData = $(this).val();
      if (repoData) {
        const repo = JSON.parse(repoData);
        selectedRepo = repo;

        debugLog('Selected Repository:', repo);

        // Show repository information
        showRepositoryInfo(repo);

        // Show type step (new)
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

    // Type change (NEW)
    $("#wpvtp-repo-type").on("change", function () {
      const type = $(this).val();
      if (type && selectedRepo) {
        debugLog('Selected Type:', type);
        
        // Save the type in the selectedRepo object
        selectedRepo.detected_type = type;
        
        // Load branches
        loadBranches(selectedRepo.owner.login, selectedRepo.name);
        showStep("branch");
      } else {
        hideStep("branch");
        hideStep("custom-name");
        hideStep("confirm");
      }
    });

    // Branch change
    $("#wpvtp-branch").on("change", function () {
      const branch = $(this).val();
      if (branch && selectedRepo) {
        debugLog('Selected Branch:', branch);
        
        // Show custom name step
        showStep("custom-name");
        
        // Suggest default name based on repo and branch
        suggestCustomName();
        
        showInstallSummary();
        showStep("confirm");
      } else {
        hideStep("custom-name");
        hideStep("confirm");
      }
    });

    // Custom name change - update summary
    $("#wpvtp-custom-name").on("input", function () {
      if (selectedRepo && $("#wpvtp-branch").val()) {
        showInstallSummary();
      }
    });

    // Submission of the installation form
    $("#wpvtp-install-form").on("submit", function (e) {
      e.preventDefault();
      installRepository();
    });
  }

  /**
   * Suggest custom name based on repository and branch
   */
  function suggestCustomName() {
    if (!selectedRepo) return;
    
    const branch = $("#wpvtp-branch").val();
    const repoName = selectedRepo.name;
    
    // Only suggest if it's not the main branch and it's a theme
    if (selectedRepo.detected_type === 'theme' && 
        branch && 
        !['main', 'master'].includes(branch)) {
      
      const suggestedName = repoName + ' (' + branch.charAt(0).toUpperCase() + branch.slice(1) + ')';
      $("#wpvtp-custom-name").attr('placeholder', 'E.g.: ' + suggestedName);
    } else {
      $("#wpvtp-custom-name").attr('placeholder', 'Leave empty to use the repository name');
    }
  }

  /**
   * Initialize repository table actions
   */
  function initializeActions() {
    // Update repository
    $(document).on("click", ".wpvtp-update-repo", function (e) {
      e.preventDefault();
      const localPath = $(this).data("path");
      const button = $(this);

      updateRepository(localPath, button);
    });

    // Switch branch
    $(document).on("click", ".wpvtp-switch-branch", function (e) {
      e.preventDefault();
      const localPath = $(this).data("path");
      const repoUrl = $(this).data("repo-url");

      showBranchModal(localPath, repoUrl);
    });

    // Remove repository
    $(document).on("click", ".wpvtp-remove-repo", function (e) {
      e.preventDefault();
      const localPath = $(this).data("path");
      const repoName = $(this).data("name");

      showConfirmModal(
        "Confirm Deletion",
        `Are you sure you want to delete the repository "${repoName}"? This action cannot be undone.`,
        "Delete",
        "button-link-delete",
        function () {
          removeRepository(localPath);
        }
      );
    });
  }

  /**
   * Initialize token validation
   */
  function initializeTokenValidation() {
    $("#wpvtp-validate-token").on("click", function (e) {
      e.preventDefault();
      const token = $('input[name="github_token"]').val();

      if (!token || token === "••••••••••••••••") {
        showTokenValidation("Please enter a valid token", "error");
        return;
      }

      validateToken(token);
    });
  }

  /**
   * Disconnect from GitHub
   */
  window.disconnectGitHub = function() {
    if (
      !confirm(
        "Are you sure you want to disconnect from GitHub? You will lose access to private repositories."
      )
    ) {
      return;
    }

    debugLog('Disconnecting from GitHub...');

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
            "❌ Error while disconnecting: " + response.data.error,
            "error"
          );
        }
      })
      .fail(function () {
        showNotification("❌ Connection error while disconnecting", "error");
      });
  };

  /**
   * Load organizations from GitHub
   */
  function loadOrganizations() {
    const select = $("#wpvtp-organization");

    debugLog('Loading organizations...');

    select
      .html('<option value="">Loading organizations...</option>')
      .prop("disabled", true);

    $.post(wpvtp_ajax.ajax_url, {
      action: "wpvtp_get_organizations",
      nonce: wpvtp_ajax.nonce,
    })
      .done(function (response) {
        debugLog('Organizations response:', response);
        
        if (response.success) {
          currentOrganizations = response.data;
          populateOrganizationSelect();
        } else {
          select.html(
            '<option value="">Error loading organizations</option>'
          );
          showNotification(
            "Error loading organizations: " + response.data,
            "error"
          );

          if (
            response.data.includes("inválido") ||
            response.data.includes("401") ||
            response.data.includes("expirada")
          ) {
            showNotification(
              "❌ Invalid or expired session. Go to Settings to reconnect with GitHub.",
              "error"
            );
          }
        }
      })
      .fail(function () {
        select.html('<option value="">Connection error</option>');
        showNotification("Connection error while loading organizations", "error");
      })
      .always(function () {
        select.prop("disabled", false);
      });
  }

  /**
   * Populate organization select
   */
  function populateOrganizationSelect() {
    const select = $("#wpvtp-organization");
    let html = '<option value="">Select an organization or user...</option>';

    currentOrganizations.forEach(function (org) {
      const type = org.type === 'Organization' ? 'org' : 'user';
      const icon = org.type === 'Organization' ? '🏢' : '👤';
      const label = org.login + (org.description ? ' - ' + org.description : '');
      
      html += `<option value="${org.login}" data-type="${type}">${icon} ${label}</option>`;
    });

    select.html(html);
    debugLog(`${currentOrganizations.length} organizations loaded`);
  }

  /**
   * Load repositories for an organization
   */
  function loadRepositories(owner, type) {
    const select = $("#wpvtp-repository");

    debugLog('Loading repositories for:', { owner, type });

    select
      .html('<option value="">Loading repositories...</option>')
      .prop("disabled", true);

    $.post(wpvtp_ajax.ajax_url, {
      action: "wpvtp_get_repositories",
      owner: owner,
      type: type,
      nonce: wpvtp_ajax.nonce,
    })
      .done(function (response) {
        debugLog('Repositories response:', response);
        
        if (response.success) {
          currentRepositories = response.data;
          populateRepositorySelect();
          
          // Count private repos
          const privateCount = currentRepositories.filter(r => r.private).length;
          if (privateCount > 0) {
            debugLog(`✅ ${privateCount} private repository(ies) found`);
          }
        } else {
          select.html('<option value="">Error loading repositories</option>');
          showNotification(
            "Error loading repositories: " + response.data,
            "error"
          );
        }
      })
      .fail(function () {
        select.html('<option value="">Connection error</option>');
        showNotification("Connection error while loading repositories", "error");
      })
      .always(function () {
        select.prop("disabled", false);
      });
  }

  /**
   * Populate repository select
   * UPDATED: Now shows visual indicator for private repos
   */
  function populateRepositorySelect() {
    const select = $("#wpvtp-repository");
    
    if (currentRepositories.length === 0) {
      select.html('<option value="">No repositories available</option>');
      return;
    }

    let html = '<option value="">Select a repository...</option>';

    // Sort: private first, then by name
    const sortedRepos = currentRepositories.sort((a, b) => {
      if (a.private === b.private) {
        return a.name.localeCompare(b.name);
      }
      return a.private ? -1 : 1;
    });

    sortedRepos.forEach(function (repo) {
      // Visual indicator for private repos
      const privacyIcon = repo.private ? '🔒 ' : '🌐 ';
      const repoData = JSON.stringify(repo);
      const description = repo.description ? ' - ' + repo.description.substring(0, 50) : '';
      
      html += `<option value='${repoData}'>${privacyIcon}${repo.name}${description}</option>`;
    });

    select.html(html);
    
    // Show message if there are private repos
    const privateCount = currentRepositories.filter(r => r.private).length;
    if (privateCount > 0) {
      showNotification(
        `✅ ${privateCount} private repository(ies) available`,
        "success"
      );
    }
    
    debugLog(`${currentRepositories.length} repositories loaded (${privateCount} private)`);
  }

  /**
   * Show selected repository information
   */
  function showRepositoryInfo(repo) {
    const privacyBadge = repo.private 
      ? '<span style="background: #d63638; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px; margin-left: 8px;">🔒 PRIVATE</span>'
      : '<span style="background: #00a32a; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px; margin-left: 8px;">🌐 PUBLIC</span>';
    
    const languageBadge = repo.language 
      ? `<span style="background: #f0f0f0; padding: 3px 8px; border-radius: 3px; font-size: 11px; margin-left: 8px;">${repo.language}</span>`
      : '';

    $("#repo-description").html(
      (repo.description || "No description") + privacyBadge + languageBadge
    );

    $("#wpvtp-repo-info").show().addClass("wpvtp-fade-in");
  }

  /**
   * Load branches for a repository
   */
  function loadBranches(owner, repo) {
    const select = $("#wpvtp-branch");

    debugLog('Loading branches for:', { owner, repo });

    select
      .html('<option value="">Loading branches...</option>')
      .prop("disabled", true);

    $.post(wpvtp_ajax.ajax_url, {
      action: "wpvtp_get_branches",
      owner: owner,
      repo: repo,
      nonce: wpvtp_ajax.nonce,
    })
      .done(function (response) {
        debugLog('Branches response:', response);
        
        if (response.success) {
          currentBranches = response.data;
          populateBranchSelect();
        } else {
          select.html('<option value="">Error loading branches</option>');
          showNotification("Error loading branches: " + response.data, "error");
        }
      })
      .fail(function () {
        select.html('<option value="">Connection error</option>');
        showNotification("Connection error while loading branches", "error");
      })
      .always(function () {
        select.prop("disabled", false);
      });
  }

  /**
   * Populate branch select
   */
  function populateBranchSelect() {
    const select = $("#wpvtp-branch");
    
    if (currentBranches.length === 0) {
      select.html('<option value="">No branches available</option>');
      return;
    }

    let html = '<option value="">Select a branch...</option>';

    currentBranches.forEach(function (branch) {
      // Highlight main branch
      const isMain = branch.name === 'main' || branch.name === 'master';
      const star = isMain ? '⭐ ' : '';
      
      html += `<option value="${branch.name}">${star}${branch.name}</option>`;
    });

    select.html(html);
    debugLog(`${currentBranches.length} branches loaded`);
  }

  /**
   * Show installation summary
   */
  function showInstallSummary() {
    if (!selectedRepo) return;

    const branch = $("#wpvtp-branch").val();
    const customName = $("#wpvtp-custom-name").val().trim();
    const finalName = customName || selectedRepo.name;
    
    const privacyBadge = selectedRepo.private ? '🔒 Private' : '🌐 Public';
    const typeLabel = selectedRepo.detected_type === 'theme' ? '🎨 Theme' : '🔌 Plugin';

    const summary = `
      <h4>Installation Summary</h4>
      <p><strong>Repository:</strong> ${selectedRepo.full_name} (${privacyBadge})</p>
      <p><strong>Branch:</strong> ${branch}</p>
      <p><strong>Type:</strong> ${typeLabel}</p>
      ${customName ? `<p><strong>Custom Name:</strong> ${customName}</p>` : ''}
      <p><strong>Final Name:</strong> ${finalName}</p>
    `;

    $("#wpvtp-install-summary").html(summary);
  }

  /**
   * Install repository
   */
  function installRepository() {
    if (!selectedRepo) return;

    const branch = $("#wpvtp-branch").val();
    const customName = $("#wpvtp-custom-name").val().trim();
    const button = $("#wpvtp-install-form button[type='submit']");
    const resultsDiv = $("#wpvtp-install-results");

    debugLog('Starting installation...', {
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
        debugLog('Installation response:', response);
        
        if (response.success) {
          resultsDiv
            .addClass("success")
            .html(
              `
                <h3>✅ Installation Successful</h3>
                <p>${response.message}</p>
                <p style="margin-top: 15px;">
                  <a href="${wpvtp_ajax.admin_url}admin.php?page=wp-versions-themes-plugins" class="button button-primary">View Repositories</a>
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
                <h3>❌ Installation Error</h3>
                <p>${response.error}</p>
              `
            )
            .show()
            .addClass("wpvtp-fade-in");
        }
      })
      .fail(function (xhr, status, error) {
        debugLog('Installation error:', { xhr, status, error });
        
        resultsDiv
          .addClass("error")
          .html(
            `
              <h3>❌ Connection Error</h3>
              <p>Could not connect to the server. Please try again.</p>
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
   * Update repository
   */
  function updateRepository(localPath, button) {
    const originalText = button.text();
    button.addClass("loading").prop("disabled", true).text('Updating...'); // Manual text change

    debugLog('Updating repository:', localPath);

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
          "❌ Connection error while updating repository",
          "error"
        );
      })
      .always(function () {
        button.removeClass("loading").prop("disabled", false).text(originalText);
      });
  }

  /**
   * Remove repository
   */
  function removeRepository(localPath) {
    debugLog('Removing repository:', localPath);
    
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
          "❌ Connection error while removing repository",
          "error"
        );
      });
  }

  /**
   * Show modal to switch branch
   */
  function showBranchModal(localPath, repoUrl) {
    const urlParts = repoUrl
      .replace("https://github.com/", "")
      .replace(".git", "")
      .split("/");
    const owner = urlParts[0];
    const repo = urlParts[1];

    showModal(
      "Switch Branch",
      `
        <p>Select the new branch for this repository:</p>
        <select id="branch-select" style="width: 100%; margin-bottom: 15px;">
          <option value="">Loading branches...</option>
        </select>
        <div style="text-align: right;">
          <button type="button" class="button" onclick="closeModal()">Cancel</button>
          <button type="button" class="button button-primary" id="confirm-branch-switch" disabled>Switch Branch</button>
        </div>
      `,
      function () {
        loadBranchesForModal(owner, repo, localPath);
      }
    );
  }

  /**
   * Load branches for the modal
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
          let html = '<option value="">Select a branch...</option>';
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
          select.html('<option value="">Error loading branches</option>');
        }
      })
      .fail(function () {
        select.html('<option value="">Connection error</option>');
      });
  }

  /**
   * Switch repository branch
   */
  function switchBranch(localPath, newBranch) {
    debugLog('Switching branch:', { localPath, newBranch });
    
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
        showNotification("❌ Connection error while switching branch", "error");
      });
  }

  /**
   * UI Utilities
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
          <span class="screen-reader-text">Close this notice.</span>
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
    confirmText = "Confirm",
    confirmClass = "button-primary",
    onConfirm = null
  ) {
    showModal(
      title,
      `
        <p>${message}</p>
        <div style="text-align: right; margin-top: 20px;">
          <button type="button" class="button" onclick="closeModal()">Cancel</button>
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