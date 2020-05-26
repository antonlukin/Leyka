/*
 * Star form template functionality and handlers
 */

(function($){

    function init() {
		bindEvents();
    }

    /* event handlers */
    function bindEvents() {

        bindModeEvents();
        bindAgreeEvents();
        bindSwiperEvents();
        bindAmountEvents();
        bindDonorDataEvents();
        bindSubmitPaymentFormEvent();
        bindPMEvents();

        // Window resize events:
        $(window).on('resize.leyka', function(){
            $('.full-list.equalize-elements-width').each(function(){
                equalizeFormElementsWidth($(this));
            });
        }).resize();

    }

    function equalizeFormElementsWidth($elements_wrapper){

        let width = 0;
        $elements_wrapper.children(':not('+$elements_wrapper.data('equalize-elements-exceptions')+')').each(function(){

            let $element = jQuery(this);
            if( !width ) {
                width = $element.outerWidth();
            }

            if($element.outerWidth() !== width) {
                $element.css('flex', width+'px 0 1');
            }

        });

    }
	
	function resize(e, element, k) {

        let val = $.trim(element.value);

        if( !val ) {

            $(element).addClass('empty');
            
            if( !e || e.type == 'blur' ) {
                setAmountPlaceholder(element);
                val = $(element).attr('placeholder');
                $(element).siblings('.currency').hide();
                $(element).addClass('show-ph');
            } else if(e.type == 'focus') {
                $(element).siblings('.currency').show();
                $(element).removeClass('show-ph');
            }

        } else {
            $(element).removeClass('empty');
            $(element).removeClass('show-ph');
        }
        
        setAmountInputValue($(element).closest('.leyka-tpl-star-form'), $(element).val());

	}
    
    function setAmountPlaceholder(element) {
        if(isMobileScreen()) {
            $(element).prop('placeholder', $(element).data('mobile-ph'));
        } else {
            $(element).prop('placeholder', $(element).data('desktop-ph'));
        }
    }
	
	function bindAmountEvents() {
		
		function resizable(el, factor) {
			var k = Number(factor) || 7.7;
			var e = 'keyup,keypress,focus,blur,change'.split(',');
			for(var i in e) {
				el.addEventListener(e[i], function(e){resize(e, el, k);}, false);
			}
			resize(null, el, k);
		}

		$('.donate_amount_flex').each(function(i, el) {
            if(parseInt($(this).css('font-size')) <= 16) {
                resizable(el, 7);
            }
            else {
                resizable(el, 11.1);
            }
            setAmountPlaceholder(el);
		});
        
        $('.leyka-tpl-star-form .amount__figure .swiper-item.selected').each(function(i, el){
            setAmountInputValue($(el).closest('.leyka-tpl-star-form'), getAmountValueFromControl($(el)));
        });
        
        $('.leyka-tpl-star-form .flex-amount-item').on('blur', 'input', function(){
            $(this).closest('.swiper-item').removeClass('focus');
            if(!$.trim($(this).val())) {
                $(this).closest('.swiper-item').addClass('empty');
            }
        });
        
        var keypressTimeout = null;
        $('.leyka-tpl-star-form .flex-amount-item').on('keyup', 'input', function(){
            var $_form = $(this).closest('form.leyka-pm-form');
            
            if(keypressTimeout) {
                clearTimeout(keypressTimeout);
                keypressTimeout = null;
            }
            
            if(!keypressTimeout) {
                keypressTimeout = setTimeout(function(){
                    checkFormFillCompletion($_form);
                    keypressTimeout = null;
                }, 500);
            }
        });
        
        $('.leyka-tpl-star-form .flex-amount-item input').each(function(i, el){
            if( !$.trim($(el).val()) ) {
                $(el).parent().addClass('empty');
            }
        });
	}
    
    function getAmountValueFromControl($el) {
        var $predefinedAmount = $el.find('span.amount');
        var val = '';
        
        if($predefinedAmount.length > 0) {
            val = $el.find('span.amount').text();
        }
        else {
            val = $el.find('input.donate_amount_flex').val();
        }
        
        return val;
    }
    
    function setAmountInputValue($form, amount) {
        $form.find('input.leyka_donation_amount').val(parseFloat(amount.replace(' ', '')));
    }

    function bindModeEvents() {

        $('.leyka-tpl-star-form .section__fields.periodicity').on('click', 'a', function(e){
			e.preventDefault();
			
			$(this).closest('.section__fields').find('a').removeClass('active');
			$(this).addClass('active');
            
            var $_form = $(this).closest('form.leyka-pm-form');
            setupPeriodicity($_form);
            setupSwiperWidth($_form);
        });
        
        $('.leyka-tpl-star-form form.leyka-pm-form').each(function(){
            setupPeriodicity($(this));
            setupSwiperWidth($(this));
        });
    }

    function setupSwiperWidth($_form) {
        // amount swiper setup
        $('.amount__figure.star-swiper .swiper-list .swiper-item').last().css('margin-right', '0px');
        
        // pm swiper setup
        var $swiper = $_form.find('.payments-grid .star-swiper');
        // $list is empty in full-list width mode
        var $list = $swiper.find('.swiper-list');

        var $activeItem = $swiper.find('.swiper-item.selected:not(.disabled)').first();
        if($activeItem.length == 0) {
            $swiper.find('.swiper-item:not(.disabled)').first().addClass('selected');
            $activeItem = $swiper.find('.swiper-item.selected:not(.disabled)').first();
            $activeItem.find('input[type=radio]').prop('checked', true).change();
        }

        $list.find('.swiper-item:not(.disabled)').css('margin-right', '16px');
        $list.find('.swiper-item:not(.disabled)').last().css('margin-right', '0px');        
        $list.css('width', '100%');

        // fix max width must work in swiper and full width mode, so use $swiper insted $list
        var maxWidth = $swiper.closest('.leyka-payment-form').width();

        if($swiper.find('.full-list').length) {
            maxWidth -= 60;
            $swiper.find('.payment-opt__label').css('max-width', maxWidth);
            $swiper.find('.payment-opt__icon').css('max-width', maxWidth);
            //$list.find('.swiper-item').css('min-width', maxWidth);
        }
        else {
            maxWidth -= 184;
            $swiper.find('.payment-opt__label').css('max-width', maxWidth);
            $swiper.find('.payment-opt__icon').css('max-width', maxWidth);

            $swiper.find('.swiper-item').each(function(i, item){
                var w1 = $(item).find('.payment-opt__label').width();
                var w2 = $(item).find('.pm-icon').length * 40; // max width of pm icon
                $(item).css('min-width', Math.min(maxWidth, Math.max(w1, w2)) + 64);
            });

            // fix for FF and Safari
            var $activePMItem = $swiper.find('.swiper-item:not(.disabled)');
            if($activePMItem.length <= 1) {
                $activePMItem.css('width', '100%');
            }
            else {
                $activePMItem.css('width', 'auto');
            }
        }
        
        toggleSwiperArrows($swiper);
        swipeList($swiper, $activeItem);
    }

    function setupPeriodicity($_form) {

        let isRecurring = false,
            $activePeriodicityTab = $_form.find('.section__fields.periodicity a.active');

        if($activePeriodicityTab.length) {
            isRecurring = $activePeriodicityTab.data('periodicity') == 'monthly';
        } else {
            isRecurring = parseInt($_form.find('input.is-recurring-chosen').val()) == 1;
        }

        $_form.find('.section__fields.periodicity a').removeClass('active');

        if(isRecurring) {

            $_form.find('.section__fields.periodicity a[data-periodicity="monthly"]').addClass('active');
            $_form.find('input.is-recurring-chosen').val('1');
            $_form.find('.payments-grid .swiper-item').each(function(i, element){
                if($(element).find('input[data-has-recurring="0"]').length > 0) {
                    $(element)
                        .addClass('disabled')
                        .removeClass('selected')
                        .find('input[type="radio"]')
                            .prop('checked', false);
                }
            });

        } else {

            $_form.find('.section__fields.periodicity a[data-periodicity="once"]').addClass('active');
            $_form.find('input.is-recurring-chosen').val('0');
            $_form.find('.payments-grid .swiper-item').each(function(i, element){
                if($(element).find('input[data-has-recurring="0"]').length > 0) {
                    $(element).removeClass('disabled');
                }
            });

        }

        let $available_pm_blocks = $_form.find('.payments-grid .swiper-item:not(.disabled)'),
            $single_pm_icon_block = $_form.find('.single-pm-icon'),
            $pm_form_section = $_form.find('.section--cards');

        if($available_pm_blocks.length === 1) {

            $single_pm_icon_block.html($available_pm_blocks.find('.payment-opt__icon').html()).show();
            $pm_form_section.hide();

        } else {

            $single_pm_icon_block.hide();
            $pm_form_section.show();

        }
        
        checkFormFillCompletion($_form);

    }

    function bindSwiperEvents() {
        $('.leyka-tpl-star-form .star-swiper').on('click', '.swiper-item', function(e){

            let $this = $(this),
                $swiper = $this.closest('.star-swiper');

        	if($this.hasClass('selected')) {
        		return;
        	}

            $this.siblings('.swiper-item.selected').removeClass('selected');
            $this.addClass('selected');
            $this.find('input[type="radio"]').prop('checked', true).change();

            swipeList($swiper, $this);
            toggleSwiperArrows($swiper);

            if($this.hasClass('flex-amount-item')) {
                $this.find('input[type="number"]').focus();
                $this.addClass('focus').removeClass('empty');
            }
            
            if($swiper.hasClass('amount__figure')) {
                setAmountInputValue($this.closest('.leyka-tpl-star-form'), getAmountValueFromControl($this));
            }

            checkFormFillCompletion($swiper.closest('form.leyka-pm-form'));

        });

        $('.leyka-tpl-star-form .star-swiper .swiper-item:first').click();

        $('.leyka-tpl-star-form .star-swiper .swiper-item.selected')
            .find('input[type="radio"]')
                .prop('checked', true)
                .change();

        $('.leyka-tpl-star-form .star-swiper').on('click', 'a.swiper-arrow', function(e){

            e.preventDefault();

			var $this = $(this),
                $swiper = $this.closest('.star-swiper'),
                $activeItem = $swiper.find('.swiper-item.selected:not(.disabled)'),
                $nextItem = null;

			if($this.hasClass('swipe-right')) {
				$nextItem = $activeItem.next('.swiper-item:not(.disabled)');
			} else {
				$nextItem = $activeItem.prev('.swiper-item:not(.disabled)');
			}

			if( !$nextItem.length ) {
				if($this.hasClass('swipe-right')) {
					$nextItem = $swiper.find('.swiper-item:not(.disabled)').first();
				} else {
					$nextItem = $swiper.find('.swiper-item:not(.disabled)').last();
				}
			}

			if($nextItem.length) {
				$activeItem.removeClass('selected');
				$nextItem.addClass('selected');
                $nextItem.find('input[type="radio"]').prop('checked', true).change();
			}

            swipeList($swiper, $nextItem);
            toggleSwiperArrows($swiper);

            if($nextItem.hasClass('flex-amount-item')) {
                $nextItem.find('input[type=number]').focus();
                $nextItem.addClass('focus').removeClass('empty');
            }

            if($swiper.hasClass('amount__figure')) {
                setAmountInputValue($nextItem.closest('.leyka-tpl-star-form'), getAmountValueFromControl($nextItem));
            }

            checkFormFillCompletion($swiper.closest('form.leyka-pm-form'));

        });
        
        $('.star-swiper').each(function() {
            toggleSwiperArrows($(this));
        });
    }
    
    function swipeList($swiper, $activeItem) {
        var $list = $swiper.find('.swiper-list');

        if( !$list.length ) {
            return;
        }

        $list.stop( true, true );

        var dif = $list.width() - $swiper.width();
        if(dif <= 0) {
            $list.width($swiper.width());
            $list.css('left', 0);
            return;
        }
        
        var left = parseInt($list.css('left'));
        if($swiper.find('.swiper-item:not(.disabled)').first().hasClass('selected')) {
            left = 0;
        }
        else if($swiper.find('.swiper-item:not(.disabled)').last().hasClass('selected')) {
            left = -dif;
        }
        else {
            left = $swiper.width() / 2 - ($activeItem.offset().left - $list.offset().left) - $activeItem.width() / 2;
            left -= 24; // minus margin * 1.5
        }
        
        $list.animate({
            'left': left
        });
    }
    
    function toggleSwiperArrows($swiper) {

        let $list = $swiper.find('.swiper-list'),
            listWidth = 0;

        if(isMobileScreen()) {
            $list.width($swiper.width());
        } else {
            $list.find('.swiper-item:not(.disabled)').each(function(){
                listWidth += $(this).outerWidth(true);
            });
            $list.width(listWidth);
        }

        if($list.find('.swiper-item:not(.disabled)').length <= 1) {
            $swiper.addClass('only-one-item');
        } else {
            $swiper.removeClass('only-one-item');
        }

        if($list.width() <= $swiper.width()) {

            $swiper
                .removeClass('show-left-arrow')
                .removeClass('show-right-arrow');

            $list.width($swiper.width()).css('left', 0);

            return;

        }

        if($swiper.find('.swiper-item:not(.disabled)').first().hasClass('selected')) {
            $swiper.removeClass('show-left-arrow');
        } else {
            $swiper.addClass('show-left-arrow');
        }

        if($swiper.find('.swiper-item:not(.disabled)').last().hasClass('selected')) {
            $swiper.removeClass('show-right-arrow');
        } else {
            $swiper.addClass('show-right-arrow');
        }

    }
    
    // agree functions
    function bindAgreeEvents() {

        bindOfertaEvents();
        bindPdEvents();
        
        // agree
        $('.leyka-tpl-star-form .donor__oferta').on('change.leyka', 'input:checkbox', function(){

            let $donorOferta = $(this).closest('.donor__oferta');
            
            if( $donorOferta.find('input:checkbox.required:not(:checked)').length ) {
                $donorOferta.addClass('invalid');
            } else {
                $donorOferta.removeClass('invalid');
            }
            
            checkFormFillCompletion($(this).closest('form.leyka-pm-form'));

        });

    }
    
    function bindOfertaEvents() {
        
        $('.leyka-tpl-star-form .leyka-js-oferta-trigger').on('click.leyka', function(e){

            e.preventDefault();

            let $form = $(this).parents('.leyka-tpl-star-form');
            $form
                .addClass('leyka-pf--oferta-open')
                .find('.leyka-pf__agreement')
                    .css('top', getAgreeModalTop($form));

            // $([document.documentElement, document.body]).animate({
            //     scrollTop: $form.offset().top - 64
            // });

        });

        $('.leyka-tpl-star-form .leyka-pf__agreement.oferta .agreement__close').on('click.leyka', function(e){

            e.preventDefault();

            $(this).parents('.leyka-tpl-star-form').removeClass('leyka-pf--oferta-open');

        });
    }

    function bindPdEvents() {

        $('.leyka-tpl-star-form .leyka-js-pd-trigger').on('click.leyka', function(e){

            e.preventDefault();

            let $form = $(this).parents('.leyka-tpl-star-form');
            $form
                .addClass('leyka-pf--pd-open')
                .find('.leyka-pf__agreement')
                    .css('top', getAgreeModalTop($form));

            $([document.documentElement, document.body]).animate({
                scrollTop: $form.offset().top - 64
            });

        });

        $('.leyka-tpl-star-form .leyka-pf__agreement.pd .agreement__close').on('click.leyka', function(e){

            e.preventDefault();

            $(this).parents('.leyka-tpl-star-form').removeClass('leyka-pf--pd-open');

        });
    }

    function getAgreeModalTop($form) {

        let $wp_admin_bar = $('#wpadminbar');

        return ($wp_admin_bar.length ? $wp_admin_bar.height() : 32) + 'px';

    }

    function addError($errors_block, error_html) {

        if( !$errors_block.length || !error_html.length ) {
            return true;
        }

        $errors_block.html(error_html).show();

        // Center the error block in the viewport
        $('html, body').animate({
            scrollTop: $errors_block.offset().top - ($(window).height() - $errors_block.outerHeight()) / 2
        }, 250);

        return false;

    }

    function bindSubmitPaymentFormEvent() {

        $('.leyka-tpl-star-form').on('submit.leyka', 'form.leyka-pm-form', function(e){

            var $form = $(this),
                $errors = $form.parents('.leyka-payment-form').siblings('.leyka-submit-errors'),
                $pm_selected = $form.find('input[name="leyka_payment_method"]:checked');

			e.preventDefault();

            if( !leykaValidateForm($form) ) { // Form errors exist

                e.preventDefault();
                e.stopPropagation();
                return;

            }

            if($pm_selected.data('processing') !== 'default') {

                if($pm_selected.data('processing') !== 'custom-process-submit-event') {
                    e.stopPropagation();
                }
                return;

            }

            // Open "waiting" form section:
            var $redirect_section = $form.closest('.leyka-pf').find('.leyka-pf__redirect'),
                data_array = $form.serializeArray(),
                data = {action: 'leyka_ajax_get_gateway_redirect_data'};

            for(var i = 0; i < data_array.length; i++) {
                data[data_array[i].name] = data_array[i].value;
            }

            if($pm_selected.data('ajax-without-form-submission')) {
                data['without_form_submission'] = true;
            }

            // Get gateway redirection form and submit it manually:
            $.post(leyka_get_ajax_url(), data).done(function(response){

                response = $.parseJSON(response);

                // Wrong answer from ajax handler:
                if( !response || typeof response.status === 'undefined' ) {
                    return false;
                } else if(response.status !== 0 && typeof response.message !== 'undefined') {
                    return addError($errors, response.message);
                } else if( !response.payment_url ) {
                    return false;
                }

                var redirect_form_html = '<form class="leyka-auto-submit" action="'+response.payment_url+'" method="post">';

                $.each(response, function(field_name, value){
                    if(field_name !== 'payment_url') {
                        redirect_form_html += '<input type="hidden" name="'+field_name+'" value="'+value+'">';
                    }
                });
                redirect_form_html += '</form>';

                $redirect_section.append(redirect_form_html);

                if(typeof response.submission_redirect_type === 'undefined' || response.submission_redirect_type === 'auto') {
                    $redirect_section.find('.leyka-auto-submit').submit();
                } else if(response.submission_redirect_type === 'redirect') {
                    window.location.href = $redirect_section.find('.leyka-auto-submit').attr('action'); // Don't use prop() here
                }

            });

        });

    }
    
    function bindDonorDataEvents() {
        var keypressTimeout = null;
        $('.leyka-tpl-star-form .donor__textfield').on('keyup', 'input,textarea', function(){
            var $_form = $(this).closest('form.leyka-pm-form');
            
            if(keypressTimeout) {
                clearTimeout(keypressTimeout);
                keypressTimeout = null;
            }
            
            if(!keypressTimeout) {
                keypressTimeout = setTimeout(function(){
                    checkFormFillCompletion($_form);
                    keypressTimeout = null;
                }, 500);
            }
        });
    }
    
    function checkFormFillCompletion($_form) {
        $_form.find('input[type=submit]').prop('disabled', !isFormFill($_form));
    }
    
    function isFormFill($_form) {
        
		var is_filled = true,
			email = $.trim($_form.find('.donor__textfield--email input').val()),
			$amount_field = $_form.find('.amount__figure input.leyka_donation_amount'),
			amount = parseInt($amount_field.val().replace(/\s/g, '')),
			$agree_terms = $_form.find('.donor__oferta input[name="leyka_agree"]'),
			$agree_pd = $_form.find('.donor__oferta input[name="leyka_agree_pd"]');

		if($_form.find('.donor__textfield--name input').val().length === 0) {
            is_filled = false;
		}

		if(email.length === 0) {
            is_filled = false;
		}

		if(
			($agree_terms.length && !$agree_terms.prop('checked')) ||
			($agree_pd.length && !$agree_pd.prop('checked'))
		) {
            is_filled = false;
		}

		if(amount <= 0) {
            is_filled = false;
		}
        
        return is_filled;
    }
    
    function bindPMEvents() {
        $('.leyka-tpl-star-form form.leyka-pm-form').each(function(){

            let $_form = $(this);

            toggleStaticPMForm($_form);
            togglePmSpecialFields($_form);

            $(this).find('input.payment-opt__radio').change(function(){

                if($(this).prop('checked')) {

                    toggleStaticPMForm($_form);
                    togglePmSpecialFields($_form);

                }

                // console.log($_form.find('.equalize-elements-width'))

                // Equalize the Donor info fields lengths:
                $_form.find('.section--person .equalize-elements-width').each(function(){
                    equalizeFormElementsWidth($(this));
                });

            });
        });

        $('.leyka-tpl-star-form .payments-grid .swiper-item.selected').each(function(i, el){
            $(this).click();
        });
    }
    
    function toggleStaticPMForm($form) {

        var $pmRadio = $form.find('input[name="leyka_payment_method"]:checked');

        if($pmRadio.data('processing') === 'static') {
            $form.find('.section--static.' + $pmRadio.val()).show();
            $form.find('.section--person').hide();
        } else {
            $form.find('.section--static').hide();
            $form.find('.section--person').show();
        }

    }

    function togglePmSpecialFields($form) {

        var $pm_radio = $form.find('input[name="leyka_payment_method"]:checked');

        $form.find('.special-field').hide();
        $form.find('.special-field.'+$pm_radio.val()).show();

    }

    function isMobileScreen() {
        return $(document).width() < 640;
    }

	init();

}( jQuery ));
