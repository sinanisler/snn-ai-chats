document.addEventListener('DOMContentLoaded', function() {
    // Initialize Tippy.js tooltips
    function initializeTooltips() {
        if (typeof tippy === 'function') { // Check if tippy is loaded
            tippy('.snn-tooltip', {
                content(reference) {
                    const title = reference.getAttribute('data-tippy-content');
                    return title;
                },
                allowHTML: true,
                placement: 'top-start',
                animation: 'fade',
                theme: 'light-border',
            });
        } else {
            console.warn('Tippy.js not loaded. Tooltips will not be initialized.');
        }
    }

    // Call on page load
    initializeTooltips();

    // Helper function for selecting elements
    const select = (selector) => document.querySelector(selector);
    const selectAll = (selector) => document.querySelectorAll(selector);

    // Handle API Provider selection on settings page
    selectAll('.api-provider-radio').forEach(radio => {
        radio.addEventListener('change', function() {
            const selectedProvider = this.value;
            selectAll('.api-settings').forEach(setting => {
                setting.classList.add('hidden');
            });
            select('#snn-' + selectedProvider + '-settings').classList.remove('hidden');

            // Clear models and details when switching providers
            // Note: We might want to keep the selected model if it's valid for the new provider,
            // but for now, clearing is safer.
            selectAll('.model-input').forEach(input => input.value = '');
            selectAll('datalist').forEach(datalist => datalist.innerHTML = '');
            selectAll('.model-details').forEach(details => details.innerHTML = '');

            // Automatically fetch models for the newly selected provider if API key exists
            if (selectedProvider === 'openrouter' && select('#openrouter_api_key').value) {
                fetchModels('openrouter', select('#openrouter_api_key').value, 'openrouter_models', 'openrouter-model-details');
            } else if (selectedProvider === 'openai' && select('#openai_api_key').value) {
                fetchModels('openai', select('#openai_api_key').value, 'openai_models', 'openai-model-details');
            }
        });
    });

    // Function to fetch models for a given provider and API key
    function fetchModels(provider, apiKey, datalistId, modelDetailsId) {
        const datalistElement = select('#' + datalistId);
        const modelDetailsElement = select('#' + modelDetailsId);

        if (!apiKey) {
            if (datalistElement) datalistElement.innerHTML = '';
            if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-red-500">Please enter an API key to fetch models.</p>';
            return;
        }

        if (datalistElement) datalistElement.innerHTML = '';
        if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-blue-500">Fetching models...</p>';

        const formData = new FormData();
        formData.append('action', 'snn_get_models');
        formData.append('nonce', snn_ai_chat_ajax.nonce);
        formData.append('provider', provider);
        formData.append('api_key', apiKey);

        fetch(snn_ai_chat_ajax.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(response => {
                if (response.success) {
                    if (datalistElement) {
                        datalistElement.innerHTML = '';
                        if (response.data && response.data.length > 0) {
                            response.data.forEach(function(model) {
                                const option = document.createElement('option');
                                option.value = model.id;
                                datalistElement.appendChild(option);
                            });
                            if (modelDetailsElement) modelDetailsElement.innerHTML = ''; // Clear fetching message
                        } else {
                            if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-red-500">No models found for this API key.</p>';
                        }
                    }
                } else {
                    if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-red-500">' + (response.data || 'Error fetching models.') + '</p>';
                }
            })
            .catch(error => {
                console.error("Fetch error fetching models:", error);
                if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-red-500">Error fetching models. Check console for details.</p>';
            });
    }

    // Function to fetch and display model details
    function fetchModelDetails(provider, model, apiKey, modelDetailsId) {
        const modelDetailsElement = select('#' + modelDetailsId);

        if (!model || !apiKey) {
            if (modelDetailsElement) modelDetailsElement.innerHTML = '';
            return;
        }

        if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-blue-500">Fetching model details...</p>';

        const formData = new FormData();
        formData.append('action', 'snn_get_model_details');
        formData.append('nonce', snn_ai_chat_ajax.nonce);
        formData.append('provider', provider);
        formData.append('model', model);
        formData.append('api_key', apiKey);

        fetch(snn_ai_chat_ajax.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(response => {
                if (response.success && response.data) {
                    const details = response.data;
                    let html = '<p><strong>ID:</strong> ' + details.id + '</p>';
                    if (details.owned_by) {
                        html += '<p><strong>Owned By:</strong> ' + details.owned_by + '</p>';
                    }
                    if (details.context_length) {
                        html += '<p><strong>Context Length:</strong> ' + details.context_length + '</p>';
                    }
                    if (details.pricing && details.pricing.prompt && details.pricing.completion) {
                        html += '<p><strong>Pricing:</strong> Input $' + details.pricing.prompt.toFixed(6) + '/M tokens, Output $' + details.pricing.completion.toFixed(6) + '/M tokens</p>';
                    }
                    if (modelDetailsElement) modelDetailsElement.innerHTML = html;
                } else {
                    if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-red-500">Could not retrieve model details.</p>';
                }
            })
            .catch(error => {
                console.error("Fetch error fetching model details:", error);
                if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-red-500">Error fetching model details. Check console for details.</p>';
            });
    }

    // Settings page model selection logic and initial load
    const openrouterApiKeyInput = select('#openrouter_api_key');
    const openaiApiKeyInput = select('#openai_api_key');
    const openrouterModelInput = select('#openrouter_model');
    const openaiModelInput = select('#openai_model');

    if (openrouterApiKeyInput) {
        openrouterApiKeyInput.addEventListener('blur', function() {
            const apiKey = this.value;
            fetchModels('openrouter', apiKey, 'openrouter_models', 'openrouter-model-details');
        });
    }

    if (openaiApiKeyInput) {
        openaiApiKeyInput.addEventListener('blur', function() {
            const apiKey = this.value;
            fetchModels('openai', apiKey, 'openai_models', 'openai-model-details');
        });
    }

    if (openrouterModelInput) {
        openrouterModelInput.addEventListener('change', function() {
            const model = this.value;
            const apiKey = openrouterApiKeyInput ? openrouterApiKeyInput.value : '';
            fetchModelDetails('openrouter', model, apiKey, 'openrouter-model-details');
        });
    }

    if (openaiModelInput) {
        openaiModelInput.addEventListener('change', function() {
            const model = this.value;
            const apiKey = openaiApiKeyInput ? openaiApiKeyInput.value : '';
            fetchModelDetails('openai', model, apiKey, 'openai-model-details');
        });
    }

    // Initial load for settings page models and details if keys are already present
    // This runs only if the current page is the settings page
    const body = select('body');
    if (body && (body.classList.contains('toplevel_page_snn-ai-chat') || body.classList.contains('ai-chat_page_snn-ai-chat-settings'))) {
        const currentProviderRadio = select('.api-provider-radio:checked');
        const currentProvider = currentProviderRadio ? currentProviderRadio.value : null;

        if (currentProvider === 'openrouter' && openrouterApiKeyInput && openrouterApiKeyInput.value) {
            fetchModels('openrouter', openrouterApiKeyInput.value, 'openrouter_models', 'openrouter-model-details');
            if (openrouterModelInput && openrouterModelInput.value) {
                fetchModelDetails('openrouter', openrouterModelInput.value, openrouterApiKeyInput.value, 'openrouter-model-details');
            }
        } else if (currentProvider === 'openai' && openaiApiKeyInput && openaiApiKeyInput.value) {
            fetchModels('openai', openaiApiKeyInput.value, 'openai_models', 'openai-model-details');
            if (openaiModelInput && openaiModelInput.value) {
                fetchModelDetails('openai', openaiModelInput.value, openaiApiKeyInput.value, 'openai-model-details');
            }
        }
    }


    // Chat edit page model selection logic and initial load
    const chatModelInput = select('#model');
    const chatModelDatalist = select('#chat_models');
    const chatModelDetailsDiv = select('#chat-model-details');

    if (chatModelInput) { // Only run if on chat edit/new page
        function loadChatModelsAndDetails() {
            // snn_ai_chat_ajax is localized with global settings
            const globalApiProvider = snn_ai_chat_ajax.global_api_provider;
            const globalApiKey = (globalApiProvider === 'openrouter') ?
                snn_ai_chat_ajax.global_openrouter_api_key :
                snn_ai_chat_ajax.global_openai_api_key;
            const globalDefaultModel = (globalApiProvider === 'openrouter') ?
                snn_ai_chat_ajax.global_openrouter_model :
                snn_ai_chat_ajax.global_openai_model;

            // Populate datalist with models from the global provider
            if (globalApiKey) {
                fetchModels(globalApiProvider, globalApiKey, 'chat_models', 'chat-model-details');
            } else {
                if (chatModelDetailsDiv) chatModelDetailsDiv.innerHTML = '<p class="text-red-500">Global API key is not configured. Cannot fetch models.</p>';
            }


            // If a specific model is already set for this chat (i.e., on edit page), load its details
            // Otherwise, set the default model from global settings
            const currentChatModel = chatModelInput.value;
            if (currentChatModel) {
                if (globalApiKey) {
                    fetchModelDetails(globalApiProvider, currentChatModel, globalApiKey, 'chat-model-details');
                }
            } else {
                // If this is a new chat and no model is selected, pre-fill with global default
                if (globalDefaultModel) {
                    chatModelInput.value = globalDefaultModel;
                    if (globalApiKey) {
                        fetchModelDetails(globalApiProvider, globalDefaultModel, globalApiKey, 'chat-model-details');
                    }
                } else {
                    if (chatModelDetailsDiv) chatModelDetailsDiv.innerHTML = '<p class="text-gray-500">No chat-specific model selected. Using global default. Configure a global model in settings.</p>';
                }
            }
        }

        loadChatModelsAndDetails();

        // Update model details when a model is selected from the datalist or typed
        chatModelInput.addEventListener('change', function() {
            const selectedModel = this.value;
            const globalApiProvider = snn_ai_chat_ajax.global_api_provider;
            const globalApiKey = (globalApiProvider === 'openrouter') ?
                snn_ai_chat_ajax.global_openrouter_api_key :
                snn_ai_chat_ajax.global_openai_api_key;

            if (selectedModel && globalApiKey) {
                fetchModelDetails(globalApiProvider, selectedModel, globalApiKey, 'chat-model-details');
            } else {
                if (chatModelDetailsDiv) chatModelDetailsDiv.innerHTML = ''; // Clear details if model or API key is missing
            }
        });

        chatModelInput.addEventListener('blur', function() { // Added blur to catch manual entries
            const selectedModel = this.value;
            const globalApiProvider = snn_ai_chat_ajax.global_api_provider;
            const globalApiKey = (globalApiProvider === 'openrouter') ?
                snn_ai_chat_ajax.global_openrouter_api_key :
                snn_ai_chat_ajax.global_openai_api_key;

            if (selectedModel && globalApiKey) {
                fetchModelDetails(globalApiProvider, selectedModel, globalApiKey, 'chat-model-details');
            } else {
                if (chatModelDetailsDiv) chatModelDetailsDiv.innerHTML = ''; // Clear details if model or API key is missing
            }
        });
    }

    // *** IMPORTANT CHANGE HERE ***
    // The chat settings form now submits directly, so this AJAX handler is removed.
    // The PHP function `save_chat_settings_form_submit` handles the POST request directly.
    /*
    const chatSettingsForm = select('#chat-settings-form');
    if (chatSettingsForm) {
        chatSettingsForm.addEventListener('submit', function(e) {
            e.preventDefault(); // Prevent default form submission as it's handled by PHP directly now

            const form = this;
            const submitBtn = select('#snn-save-chat-btn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving...';
            }

            // AJAX call is no longer used for saving chat settings.
            // The form will submit normally to the PHP handler.
            // Any success/error messages will be handled by WordPress admin notices.
        });
    }
    */

    // Handle deleting chat
    selectAll('.delete-chat-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const chatId = this.dataset.chatId;
            if (confirm('Are you sure you want to delete this chat? This action cannot be undone.')) {
                const formData = new FormData();
                formData.append('action', 'snn_delete_chat');
                formData.append('nonce', snn_ai_chat_ajax.nonce);
                formData.append('chat_id', chatId);

                fetch(snn_ai_chat_ajax.ajax_url, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(response => {
                        if (response.success) {
                            const chatCard = select('#snn-chat-card-' + chatId);
                            if (chatCard) {
                                chatCard.style.transition = 'opacity 0.3s ease-out';
                                chatCard.style.opacity = '0';
                                setTimeout(() => {
                                    chatCard.remove();
                                }, 300);
                            }
                            // Optionally show a success message via WP notices style
                            // Or just rely on fadeOut for visual confirmation
                        } else {
                            alert('Error: ' + (response.data || 'Failed to delete chat.'));
                        }
                    })
                    .catch(error => {
                        console.error("Fetch error deleting chat:", error);
                        alert('An error occurred while deleting the chat. Check console for details.');
                    });
            }
        });
    });

    // Live Preview iframe reload on settings change
    // This reloads the iframe in the 'Edit Chat' screen to show style and content changes.
    const chatSettingsFormInputs = selectAll('#chat-settings-form input, #chat-settings-form select, #chat-settings-form textarea');
    chatSettingsFormInputs.forEach(input => {
        input.addEventListener('change', reloadChatPreviewIframe);
        input.addEventListener('keyup', reloadChatPreviewIframe);
    });

    function reloadChatPreviewIframe() {
        const iframe = select('#chat-preview-iframe');
        if (iframe) {
            const currentSrc = iframe.getAttribute('src');
            // Extract chat_id from the current iframe src, assuming it's always in the format ?page=snn-ai-chat-preview&chat_id=X
            const urlParams = new URLSearchParams(currentSrc.split('?')[1]);
            const chatId = urlParams.get('chat_id');

            // Only reload if chat_id is present (i.e., it's an existing chat being edited)
            // For new chats, the preview becomes available after the initial save and page reload.
            if (chatId) {
                // To force reload, append a unique timestamp or change a query param
                // This ensures the browser doesn't serve a cached version of the iframe content.
                const newSrc = `${snn_ai_chat_ajax.ajax_url.replace('admin-ajax.php', 'admin.php?page=snn-ai-chat-preview')}&chat_id=${chatId}&_t=${new Date().getTime()}`;
                iframe.setAttribute('src', newSrc);
            }
        }
    }
});