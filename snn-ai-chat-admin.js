document.addEventListener('DOMContentLoaded', function() {
    // A cache to store model data fetched from the API
    // Structure: { 'providerName': { 'modelId': { ...modelDetails... } } }
    let cachedModelsData = {
        openrouter: {},
        openai: {}
    };

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
            selectAll('.model-input').forEach(input => input.value = '');
            selectAll('datalist').forEach(datalist => datalist.innerHTML = '');
            selectAll('.model-details').forEach(details => details.innerHTML = '');

            // Clear cache for the other provider
            if (selectedProvider === 'openrouter') {
                cachedModelsData.openai = {};
            } else if (selectedProvider === 'openai') {
                cachedModelsData.openrouter = {};
            }

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
            cachedModelsData[provider] = {}; // Clear cache for this provider
            return Promise.resolve(); // Return a resolved promise if no API key
        }

        if (datalistElement) datalistElement.innerHTML = ''; // Clear previous options
        if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-blue-500">Fetching models...</p>';

        const formData = new FormData();
        formData.append('action', 'snn_get_models');
        formData.append('nonce', snn_ai_chat_ajax.nonce);
        formData.append('provider', provider);
        formData.append('api_key', apiKey);

        return fetch(snn_ai_chat_ajax.ajax_url, { // Return the fetch promise
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(response => {
                if (response.success) {
                    if (datalistElement) {
                        datalistElement.innerHTML = ''; // Clear previous options again, just in case
                        cachedModelsData[provider] = {}; // Reset cache for this provider

                        if (response.data && response.data.length > 0) {
                            response.data.forEach(function(model) {
                                const option = document.createElement('option');
                                option.value = model.id;
                                datalistElement.appendChild(option);
                                // Store full model details in cache
                                cachedModelsData[provider][model.id] = model;
                            });
                            if (modelDetailsElement) modelDetailsElement.innerHTML = ''; // Clear fetching message
                        } else {
                            if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-red-500">No models found for this API key.</p>';
                        }
                    }
                } else {
                    // Display error message from the response data if available, otherwise a generic one
                    if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-red-500">' + (response.data || 'Error fetching models.') + '</p>';
                    cachedModelsData[provider] = {}; // Clear cache on error
                }
                return response; // Pass on the response for chaining
            })
            .catch(error => {
                console.error("Fetch error fetching models:", error); // Keep this for debugging
                if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-red-500">Error fetching models. Please check your API key and network connection.</p>';
                cachedModelsData[provider] = {}; // Clear cache on error
                throw error; // Re-throw to propagate the error
            });
    }

    // Function to fetch and display model details
    function fetchModelDetails(provider, modelId, apiKey, modelDetailsId) {
        const modelDetailsElement = select('#' + modelDetailsId);

        if (!modelId || !apiKey) {
            if (modelDetailsElement) modelDetailsElement.innerHTML = '';
            return;
        }

        // Try to retrieve from cache first
        const cachedDetail = cachedModelsData[provider] && cachedModelsData[provider][modelId];
        if (cachedDetail) {
            displayModelDetails(cachedDetail, modelDetailsElement);
            return; // Details found in cache, no need for AJAX
        }

        // If not in cache, proceed with AJAX call (e.g., for OpenAI if it doesn't return full details initially)
        if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-blue-500">Fetching model details...</p>';

        const formData = new FormData();
        formData.append('action', 'snn_get_model_details');
        formData.append('nonce', snn_ai_chat_ajax.nonce);
        formData.append('provider', provider);
        formData.append('model', modelId);
        formData.append('api_key', apiKey);

        fetch(snn_ai_chat_ajax.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(response => {
                if (response.success && response.data) {
                    displayModelDetails(response.data, modelDetailsElement);
                    // Optionally cache the individual detail if it wasn't part of the main list fetch
                    cachedModelsData[provider][modelId] = response.data;
                } else {
                    if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-red-500">Could not retrieve model details. Model may not exist or API key is invalid.</p>';
                }
            })
            .catch(error => {
                console.error("Fetch error fetching model details:", error); // Keep this for debugging
                if (modelDetailsElement) modelDetailsElement.innerHTML = '<p class="text-red-500">Error fetching model details. Please check your network connection or API key.</p>';
            });
    }

    // Helper function to render model details
    function displayModelDetails(details, element) {
        if (!details || typeof details !== 'object' || !details.id) {
            if (element) element.innerHTML = '<p class="text-red-500">Model details not available.</p>';
            return;
        }

        let html = '<p><strong>ID:</strong> ' + details.id + '</p>';

        if (details.name) { // Often present in OpenRouter data
            html += '<p><strong>Name:</strong> ' + details.name + '</p>';
        }
        if (details.owned_by) {
            html += '<p><strong>Owned By:</strong> ' + details.owned_by + '</p>';
        } else if (details.canonical_slug) { // OpenRouter often uses canonical_slug
            html += '<p><strong>Canonical Slug:</strong> ' + details.canonical_slug + '</p>';
        }
        if (details.description) {
            html += '<p><strong>Description:</strong> ' + details.description + '</p>';
        }
        if (details.context_length) {
            html += '<p><strong>Context Length:</strong> ' + details.context_length + ' tokens</p>';
        }
        if (details.hugging_face_id) {
            html += '<p><strong>Hugging Face ID:</strong> ' + details.hugging_face_id + '</p>';
        }


        // Use pricing from 'pricing' object if available, otherwise 'top_provider' (OpenRouter specific)
        let promptPrice, completionPrice;
        if (details.pricing && typeof details.pricing.prompt !== 'undefined' && typeof details.pricing.completion !== 'undefined') {
            promptPrice = parseFloat(details.pricing.prompt);
            completionPrice = parseFloat(details.pricing.completion);
        } else if (details.top_provider && details.top_provider.pricing && typeof details.top_provider.pricing.prompt !== 'undefined' && typeof details.top_provider.pricing.completion !== 'undefined') {
            // Fallback for some OpenRouter models where pricing might be nested
            promptPrice = parseFloat(details.top_provider.pricing.prompt);
            completionPrice = parseFloat(details.top_provider.pricing.completion);
        }

        if (typeof promptPrice !== 'undefined' && typeof completionPrice !== 'undefined') {
            // Check if both are zero, indicating a free model
            if (promptPrice === 0 && completionPrice === 0) {
                html += '<p><strong>Pricing:</strong> Free</p>';
            } else {
                html += `<p><strong>Pricing:</strong> Input $${promptPrice.toFixed(7)} / 1M tokens, Output $${completionPrice.toFixed(7)} / 1M tokens</p>`;
            }
        } else {
            html += '<p><strong>Pricing:</strong> Not available or unknown</p>';
        }

        if (element) element.innerHTML = html;
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

    // Event listener for model input change (settings page)
    const setupModelInputListeners = (modelInput, apiKeyInput, provider, datalistId, detailsId) => {
        if (modelInput) {
            const datalistElement = select('#' + datalistId);
            modelInput.addEventListener('change', function() {
                const model = this.value;
                const apiKey = apiKeyInput ? apiKeyInput.value : '';
                fetchModelDetails(provider, model, apiKey, detailsId);
            });

            // Handle the edge case: if input is clicked/focused and datalist is empty, try fetching models
            modelInput.addEventListener('focus', function() {
                const apiKey = apiKeyInput ? apiKeyInput.value : '';
                if (apiKey && (!datalistElement || datalistElement.options.length === 0)) {
                    fetchModels(provider, apiKey, datalistId, detailsId);
                }
            });

            // Also trigger fetch details on blur, in case user types without selecting
            modelInput.addEventListener('blur', function() {
                const model = this.value;
                const apiKey = apiKeyInput ? apiKeyInput.value : '';
                if (model && apiKey) {
                    fetchModelDetails(provider, model, apiKey, detailsId);
                }
            });
        }
    };

    setupModelInputListeners(openrouterModelInput, openrouterApiKeyInput, 'openrouter', 'openrouter_models', 'openrouter-model-details');
    setupModelInputListeners(openaiModelInput, openaiApiKeyInput, 'openai', 'openai_models', 'openai-model-details');


    // Initial load for settings page models and details if keys are already present
    const body = select('body');
    if (body && (body.classList.contains('toplevel_page_snn-ai-chat') || body.classList.contains('ai-chat_page_snn-ai-chat-settings'))) {
        const currentProviderRadio = select('.api-provider-radio:checked');
        const currentProvider = currentProviderRadio ? currentProviderRadio.value : null;

        if (currentProvider === 'openrouter' && openrouterApiKeyInput && openrouterApiKeyInput.value) {
            fetchModels('openrouter', openrouterApiKeyInput.value, 'openrouter_models', 'openrouter-model-details')
                .then(() => { // Ensure models are fetched before trying to get details
                    if (openrouterModelInput && openrouterModelInput.value) {
                        fetchModelDetails('openrouter', openrouterModelInput.value, openrouterApiKeyInput.value, 'openrouter-model-details');
                    }
                });
        } else if (currentProvider === 'openai' && openaiApiKeyInput && openaiApiKeyInput.value) {
            fetchModels('openai', openaiApiKeyInput.value, 'openai_models', 'openai-model-details')
                .then(() => { // Ensure models are fetched before trying to get details
                    if (openaiModelInput && openaiModelInput.value) {
                        fetchModelDetails('openai', openaiModelInput.value, openaiApiKeyInput.value, 'openai-model-details');
                    }
                });
        }
    }


    // Chat edit page model selection logic and initial load
    const chatModelInput = select('#model');
    const chatModelDatalist = select('#chat_models');
    const chatModelDetailsDiv = select('#chat-model-details');

    if (chatModelInput) { // Only run if on chat edit/new page
        function loadChatModelsAndDetails() {
            const globalApiProvider = snn_ai_chat_ajax.global_api_provider;
            const globalApiKey = (globalApiProvider === 'openrouter') ?
                snn_ai_chat_ajax.global_openrouter_api_key :
                snn_ai_chat_ajax.global_openai_api_key;
            const globalDefaultModel = (globalApiProvider === 'openrouter') ?
                snn_ai_chat_ajax.global_openrouter_model :
                snn_ai_chat_ajax.global_openai_model;

            // Populate datalist with models from the global provider
            if (globalApiKey) {
                fetchModels(globalApiProvider, globalApiKey, 'chat_models', 'chat-model-details')
                    .then(() => { // Ensure models are fetched and cached before trying to display details
                        // If a specific model is already set for this chat (i.e., on edit page), load its details
                        const currentChatModel = chatModelInput.value;
                        if (currentChatModel) {
                            fetchModelDetails(globalApiProvider, currentChatModel, globalApiKey, 'chat-model-details');
                        } else {
                            // If this is a new chat and no model is selected, pre-fill with global default
                            if (globalDefaultModel) {
                                chatModelInput.value = globalDefaultModel;
                                fetchModelDetails(globalApiProvider, globalDefaultModel, globalApiKey, 'chat-model-details');
                            } else {
                                if (chatModelDetailsDiv) chatModelDetailsDiv.innerHTML = '<p class="text-gray-500">No chat-specific model selected. Using global default. Configure a global model in settings.</p>';
                            }
                        }
                    });
            } else {
                if (chatModelDetailsDiv) chatModelDetailsDiv.innerHTML = '<p class="text-red-500">Global API key is not configured. Cannot fetch models.</p>';
            }
        }

        loadChatModelsAndDetails();

        // Update model details when a model is selected from the datalist or typed
        const setupChatModelInputListeners = (modelInput, detailsDivId) => {
            const datalistElement = select('#' + modelInput.getAttribute('list')); // Get the associated datalist

            modelInput.addEventListener('change', function() {
                const selectedModel = this.value;
                const globalApiProvider = snn_ai_chat_ajax.global_api_provider;
                const globalApiKey = (globalApiProvider === 'openrouter') ?
                    snn_ai_chat_ajax.global_openrouter_api_key :
                    snn_ai_chat_ajax.global_openai_api_key;

                if (selectedModel && globalApiKey) {
                    fetchModelDetails(globalApiProvider, selectedModel, globalApiKey, detailsDivId);
                } else {
                    if (chatModelDetailsDiv) chatModelDetailsDiv.innerHTML = ''; // Clear details if model or API key is missing
                }
            });

            modelInput.addEventListener('blur', function() { // Added blur to catch manual entries
                const selectedModel = this.value;
                const globalApiProvider = snn_ai_chat_ajax.global_api_provider;
                const globalApiKey = (globalApiProvider === 'openrouter') ?
                    snn_ai_chat_ajax.global_openrouter_api_key :
                    snn_ai_chat_ajax.global_openai_api_key;

                if (selectedModel && globalApiKey) {
                    fetchModelDetails(globalApiProvider, selectedModel, globalApiKey, detailsDivId);
                } else {
                    if (chatModelDetailsDiv) chatModelDetailsDiv.innerHTML = ''; // Clear details if model or API key is missing
                }
            });

            // Handle the edge case: if input is clicked/focused and datalist is empty, try fetching models
            modelInput.addEventListener('focus', function() {
                const globalApiProvider = snn_ai_chat_ajax.global_api_provider;
                const globalApiKey = (globalApiProvider === 'openrouter') ?
                    snn_ai_chat_ajax.global_openrouter_api_key :
                    snn_ai_chat_ajax.global_openai_api_key;

                if (globalApiKey && (!datalistElement || datalistElement.options.length === 0)) {
                    fetchModels(globalApiProvider, globalApiKey, 'chat_models', 'chat-model-details');
                }
            });
        };

        setupChatModelInputListeners(chatModelInput, 'chat-model-details');
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