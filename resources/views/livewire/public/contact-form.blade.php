{{--
    The /contact form (template contact.php markup kept: .matrimony-forms, .mat-label, .mat-text,
    .contact-button). Sends a support ticket (OpenTicket). `website` is a honeypot, hidden from
    people and screen readers.
--}}
<div>
    @if ($sentCode !== null)
        <div class="contact-sent" role="status">
            <i class="fa fa-check-circle" aria-hidden="true"></i>
            <h2>{{ __('Thank you — we’ve got your message') }}</h2>
            <p>
                @if ($sentCode !== '—')
                    {{ __('Your request number is :code. Our support team will reply by email, usually within one working day.', ['code' => $sentCode]) }}
                @else
                    {{ __('Our support team will reply by email, usually within one working day.') }}
                @endif
            </p>
            <x-ui.button type="button" variant="outline" wire:click="$set('sentCode', null)">{{ __('Send another message') }}</x-ui.button>
        </div>
    @else
        <form class="matrimony-forms" wire:submit="send" novalidate>
            <div class="row g-4">
                <div class="col-md-6">
                    <label class="mat-label" for="contact-first-name">{{ __('First name') }}</label>
                    <input type="text" @class(['form-control mat-text', 'is-invalid' => $errors->has('name')]) id="contact-first-name" name="first_name" wire:model="firstName"
                           placeholder="{{ __('First name') }}" autocomplete="given-name" maxlength="50" required @error('name') aria-describedby="contact-name-error" aria-invalid="true" @enderror>
                    @error('name')<p class="invalid-feedback d-block" id="contact-name-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="col-md-6">
                    <label class="mat-label" for="contact-last-name">{{ __('Last name') }}</label>
                    <input type="text" class="form-control mat-text" id="contact-last-name" name="last_name" wire:model="lastName" placeholder="{{ __('Last name') }}" autocomplete="family-name" maxlength="50">
                </div>
                <div class="col-12">
                    <label class="mat-label" for="contact-email">{{ __('Email') }}</label>
                    <input type="email" @class(['form-control mat-text', 'is-invalid' => $errors->has('email')]) id="contact-email" name="email" wire:model="email"
                           placeholder="you@example.com" autocomplete="email" required @error('email') aria-describedby="contact-email-error" aria-invalid="true" @enderror>
                    @error('email')<p class="invalid-feedback d-block" id="contact-email-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="col-12">
                    <label class="mat-label" for="contact-subject">{{ __('Topic') }}</label>
                    <select @class(['form-select mat-text', 'is-invalid' => $errors->has('category')]) id="contact-subject" name="category" wire:model="category" required
                            @error('category') aria-describedby="contact-category-error" aria-invalid="true" @enderror>
                        <option value="">{{ __('Choose a topic') }}</option>
                        @foreach ($categories as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('category')<p class="invalid-feedback d-block" id="contact-category-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="col-12">
                    <label class="mat-label" for="contact-message">{{ __('Message') }}</label>
                    <textarea @class(['form-control mat-text', 'is-invalid' => $errors->has('message')]) id="contact-message" name="message" rows="5" wire:model="message"
                              placeholder="{{ __('How can we help?') }}" maxlength="5000" required @error('message') aria-describedby="contact-message-error" aria-invalid="true" @enderror></textarea>
                    @error('message')<p class="invalid-feedback d-block" id="contact-message-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="contact-hp" aria-hidden="true">
                    <label for="contact-website">{{ __('Website') }}</label>
                    <input type="text" id="contact-website" name="website" wire:model="website" tabindex="-1" autocomplete="off">
                </div>
            </div>

            <div class="contact-button">
                <button type="submit" wire:loading.attr="disabled" wire:target="send">
                    <i class="fa fa-paper-plane" aria-hidden="true" wire:loading.remove wire:target="send"></i>
                    <span class="spinner-border spinner-border-sm" aria-hidden="true" wire:loading wire:target="send"></span>
                    {{ __('Send Message') }}
                </button>
            </div>
            <p class="form-text">{{ __('To report a member, use Report on their profile or in your conversation — it reaches our safety team faster.') }}</p>
        </form>
    @endif
</div>
