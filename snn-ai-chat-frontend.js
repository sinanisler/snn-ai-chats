jQuery(document).ready(function($) {
    $('.snn-ai-chat-widget').each(function() {
        const $this = $(this); // Cache the current widget element
        const chatId = $this.data('chat-id');
        const sessionId = $this.data('session-id');
        const initialMessageText = $this.data('initial-message'); // Get initial message from data attribute
        const collectUserInfoEnabled = $this.data('collect-user-info'); // Get boolean flag

        const chatToggle = $('#snn-chat-toggle-' + chatId);
        const chatContainer = $('#snn-chat-container-' + chatId);
        const chatClose = $('#snn-chat-close-' + chatId);
        const chatMessages = $('#snn-chat-messages-' + chatId);
        const chatInput = $('#snn-chat-input-' + chatId);
        const chatSendBtn = $('#snn-chat-send-' + chatId);
        const userInfoForm = $('#snn-user-info-form-' + chatId);
        const startChatBtn = $('#snn-start-chat-btn-' + chatId);
        const userNameInput = $('#snn-user-name-' + chatId);
        const userEmailInput = $('#snn-user-email-' + chatId);
        const messageBox = chatMessages.find('.snn-chat-message-box'); // Reference to the new message box

        // Function to display messages in the custom message box
        function showMessageBox(message) {
            messageBox.text(message).fadeIn(300);
            setTimeout(function() {
                messageBox.fadeOut(300);
            }, 3000); // Hide after 3 seconds
        }

        // Toggle chat visibility
        chatToggle.on('click', function() {
            // Close all other chat containers and show all toggles
            $('.snn-chat-container').not(chatContainer).slideUp(300);
            $('.snn-chat-toggle').not(chatToggle).removeClass('active');

            // Toggle this chat
            chatContainer.slideToggle(300, function() {
                if (chatContainer.is(':visible')) {
                    chatToggle.addClass('active');
                    if (!collectUserInfoEnabled) {
                        chatMessages.scrollTop(chatMessages[0].scrollHeight);
                    }
                } else {
                    chatToggle.removeClass('active');
                }
            });
        });

        // Close chat
        chatClose.on('click', function() {
            chatContainer.slideUp(300, function() {
                chatToggle.removeClass('active');
            });
        });

        // Function to append message to chat window
        function appendMessage(sender, message) {
            const messageClass = sender === 'user' ? 'snn-user-message' : 'snn-ai-message';
            const messageHtml = `
                <div class="snn-chat-message ${messageClass}">
                    <div class="snn-message-content">${message}</div>
                </div>`;
            chatMessages.append(messageHtml);
            chatMessages.scrollTop(chatMessages[0].scrollHeight); // Scroll to bottom
        }

        // Handle sending message
        function sendMessage() {
            const message = chatInput.val().trim();
            if (message === '') {
                return;
            }

            appendMessage('user', message);
            chatInput.val('');
            chatInput.prop('disabled', true);
            chatSendBtn.prop('disabled', true);

            // Add a loading indicator for AI response
            const loadingHtml = `
                <div class="snn-chat-message snn-ai-message snn-loading-message">
                    <div class="snn-message-content">
                        <span class="snn-loading-dots">. . .</span>
                    </div>
                </div>
            `;
            chatMessages.append(loadingHtml);
            chatMessages.scrollTop(chatMessages[0].scrollHeight);

            $.ajax({
                url: snn_ai_chat_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'snn_ai_chat_api',
                    nonce: snn_ai_chat_ajax.nonce,
                    message: message,
                    session_id: sessionId,
                    chat_id: chatId,
                    user_name: userNameInput.val(),
                    user_email: userEmailInput.val()
                },
                success: function(response) {
                    $('.snn-loading-message').remove(); // Remove loading indicator
                    if (response.success) {
                        appendMessage('ai', response.data.response);
                    } else {
                        appendMessage('ai', 'Error: ' + (response.data.response || 'Failed to get response.'));
                    }
                },
                error: function() {
                    $('.snn-loading-message').remove(); // Remove loading indicator
                    appendMessage('ai', 'An error occurred. Please try again.');
                },
                complete: function() {
                    chatInput.prop('disabled', false);
                    chatSendBtn.prop('disabled', false);
                    chatInput.focus();
                }
            });
        }

        chatSendBtn.on('click', sendMessage);
        chatInput.on('keypress', function(e) {
            if (e.which === 13) { // Enter key
                sendMessage();
            }
        });

        // Handle user info form submission
        if (userInfoForm.length) {
            startChatBtn.on('click', function() {
                if (userNameInput.val().trim() === '' || userEmailInput.val().trim() === '') {
                    showMessageBox('Please fill in your name and email.'); // Use custom message box
                    return;
                }
                userInfoForm.hide();
                chatInput.prop('disabled', false);
                chatSendBtn.prop('disabled', false);
                // Append the initial message using the data attribute value
                appendMessage('ai', initialMessageText);
                chatInput.focus();
            });
        }
    });
});
