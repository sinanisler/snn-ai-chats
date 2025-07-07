jQuery(document).ready(function($) {
    // Initialize Tippy.js tooltips
    function initializeTooltips() {
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
    }

    // Call on page load
    initializeTooltips();

    // Handle API Provider selection on settings page
    $('.api-provider-radio').on('change', function() {
        const selectedProvider = $(this).val();
        $('.api-settings').addClass('hidden');
        $('#snn-' + selectedProvider + '-settings').removeClass('hidden');
        
        // Clear models and details when switching providers
        $('.model-input').val('');
        $('datalist').empty();
        $('.model-details').empty();
    });

    // Function to fetch models for a given provider and API key
    function fetchModels(provider, apiKey, datalistId, modelDetailsId) {
        if (!apiKey) {
            $('#' + datalistId).empty();
            $('#' + modelDetailsId).html('<p class="text-red-500">Please enter an API key to fetch models.</p>');
            return;
        }

        $('#' + datalistId).empty();
        $('#' + modelDetailsId).html('<p class="text-blue-500">Fetching models...</p>');

        $.ajax({
            url: snn_ai_chat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'snn_get_models',
                nonce: snn_ai_chat_ajax.nonce,
                provider: provider,
                api_key: apiKey
            },
            success: function(response) {
                if (response.success) {
                    const datalist = $('#' + datalistId);
                    datalist.empty();
                    if (response.data && response.data.length > 0) {
                        response.data.forEach(function(model) {
                            datalist.append('<option value="' + model.id + '">');
                        });
                        $('#' + modelDetailsId).empty(); // Clear fetching message
                    } else {
                        $('#' + modelDetailsId).html('<p class="text-red-500">No models found for this API key.</p>');
                    }
                } else {
                    $('#' + modelDetailsId).html('<p class="text-red-500">' + response.data + '</p>');
                }
            },
            error: function() {
                $('#' + modelDetailsId).html('<p class="text-red-500">Error fetching models.</p>');
            }
        });
    }

    // Function to fetch and display model details
    function fetchModelDetails(provider, model, apiKey, modelDetailsId) {
        if (!model || !apiKey) {
            $('#' + modelDetailsId).empty();
            return;
        }

        $('#' + modelDetailsId).html('<p class="text-blue-500">Fetching model details...</p>');

        $.ajax({
            url: snn_ai_chat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'snn_get_model_details',
                nonce: snn_ai_chat_ajax.nonce,
                provider: provider,
                model: model,
                api_key: apiKey
            },
            success: function(response) {
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
                    $('#' + modelDetailsId).html(html);
                } else {
                    $('#' + modelDetailsId).html('<p class="text-red-500">Could not retrieve model details.</p>');
                }
            },
            error: function() {
                $('#' + modelDetailsId).html('<p class="text-red-500">Error fetching model details.</p>');
            }
        });
    }

    // Settings page model selection logic
    $('#openrouter_api_key').on('blur', function() {
        const apiKey = $(this).val();
        fetchModels('openrouter', apiKey, 'openrouter_models', 'openrouter-model-details');
    });

    $('#openai_api_key').on('blur', function() {
        const apiKey = $(this).val();
        fetchModels('openai', apiKey, 'openai_models', 'openai-model-details');
    });

    $('#openrouter_model').on('change', function() {
        const model = $(this).val();
        const apiKey = $('#openrouter_api_key').val();
        fetchModelDetails('openrouter', model, apiKey, 'openrouter-model-details');
    });

    $('#openai_model').on('change', function() {
        const model = $(this).val();
        const apiKey = $('#openai_api_key').val();
        fetchModelDetails('openai', model, apiKey, 'openai-model-details');
    });

    // Initial load for settings page models if keys are already present
    if ($('#openrouter_api_key').val()) {
        fetchModels('openrouter', $('#openrouter_api_key').val(), 'openrouter_models', 'openrouter-model-details');
    }
    if ($('#openai_api_key').val()) {
        fetchModels('openai', $('#openai_api_key').val(), 'openai_models', 'openai-model-details');
    }
    // Also load details for the currently selected model on settings page
    if ($('#openrouter_model').val() && !$('#snn-openrouter-settings').hasClass('hidden')) {
        fetchModelDetails('openrouter', $('#openrouter_model').val(), $('#openrouter_api_key').val(), 'openrouter-model-details');
    }
    if ($('#openai_model').val() && !$('#snn-openai-settings').hasClass('hidden')) {
        fetchModelDetails('openai', $('#openai_model').val(), $('#openai_api_key').val(), 'openai-model-details');
    }


    // Chat edit page model selection logic
    const chatModelInput = $('#model');
    const chatModelDatalist = $('#chat_models');
    const chatModelDetailsDiv = $('#chat-model-details');

    function loadChatModelsAndDetails() {
        const globalApiProvider = snn_ai_chat_ajax.global_api_provider;
        let globalApiKey = '';
        let globalDefaultModel = '';

        if (globalApiProvider === 'openrouter') {
            globalApiKey = snn_ai_chat_ajax.global_openrouter_api_key;
            globalDefaultModel = snn_ai_chat_ajax.global_openrouter_model;
        } else if (globalApiProvider === 'openai') {
            globalApiKey = snn_ai_chat_ajax.global_openai_api_key;
            globalDefaultModel = snn_ai_chat_ajax.global_openai_model;
        }

        // Populate datalist with models from the global provider
        fetchModels(globalApiProvider, globalApiKey, 'chat_models', 'chat-model-details');

        // If a specific model is already set for this chat, load its details
        const currentChatModel = chatModelInput.val();
        if (currentChatModel) {
            fetchModelDetails(globalApiProvider, currentChatModel, globalApiKey, 'chat-model-details');
        } else {
            // If no specific model is set, default to global model and show its details
            chatModelInput.val(globalDefaultModel);
            fetchModelDetails(globalApiProvider, globalDefaultModel, globalApiKey, 'chat-model-details');
        }
    }

    // Load models and details when chat edit page is ready
    if (chatModelInput.length) { // Only run if on chat edit page
        loadChatModelsAndDetails();

        // Update model details when a model is selected from the datalist or typed
        chatModelInput.on('change', function() {
            const selectedModel = $(this).val();
            const globalApiProvider = snn_ai_chat_ajax.global_api_provider;
            let globalApiKey = '';
            if (globalApiProvider === 'openrouter') {
                globalApiKey = snn_ai_chat_ajax.global_openrouter_api_key;
            } else if (globalApiProvider === 'openai') {
                globalApiKey = snn_ai_chat_ajax.global_openai_api_key;
            }
            fetchModelDetails(globalApiProvider, selectedModel, globalApiKey, 'chat-model-details');
        });
    }

    // Handle saving chat settings
    $('#chat-settings-form').on('submit', function(e) {
        e.preventDefault();

        const form = $(this);
        const submitBtn = $('#snn-save-chat-btn');
        submitBtn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: snn_ai_chat_ajax.ajax_url,
            type: 'POST',
            data: form.serialize() + '&action=snn_save_chat_settings&nonce=' + snn_ai_chat_ajax.nonce,
            success: function(response) {
                if (response.success) {
                    alert('Chat settings saved successfully!'); // Use alert for now, replace with custom modal later
                    if (response.data.redirect_url) {
                        window.location.href = response.data.redirect_url;
                    }
                } else {
                    alert('Error: ' + response.data); // Use alert for now
                }
            },
            error: function() {
                alert('An error occurred while saving settings.'); // Use alert for now
            },
            complete: function() {
                submitBtn.prop('disabled', false).text('Save Chat');
            }
        });
    });

    // Handle deleting chat
    $('.delete-chat-btn').on('click', function(e) {
        e.preventDefault();
        const chatId = $(this).data('chat-id');
        if (confirm('Are you sure you want to delete this chat? This action cannot be undone.')) {
            $.ajax({
                url: snn_ai_chat_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'snn_delete_chat',
                    nonce: snn_ai_chat_ajax.nonce,
                    chat_id: chatId
                },
                success: function(response) {
                    if (response.success) {
                        $('#snn-chat-card-' + chatId).fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        alert('Error: ' + response.data);
                    }
                },
                error: function() {
                    alert('An error occurred while deleting the chat.');
                }
            });
        }
    });

    // Live Preview iframe reload on settings change
    $('#chat-settings-form input, #chat-settings-form select, #chat-settings-form textarea').on('change keyup', function() {
        const iframe = $('#chat-preview-iframe');
        if (iframe.length) {
            const currentSrc = iframe.attr('src');
            const chatId = new URLSearchParams(currentSrc.split('?')[1]).get('chat_id');
            // Only reload if chat_id is present (i.e., it's an existing chat being edited)
            if (chatId) {
                // To force reload, append a timestamp or change a query param
                const newSrc = snn_ai_chat_ajax.ajax_url.replace('admin-ajax.php', 'admin.php?page=snn-ai-chat-preview') + '&chat_id=' + chatId + '&_t=' + new Date().getTime();
                iframe.attr('src', newSrc);
            }
        }
    });
});
