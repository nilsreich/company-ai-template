@if(app()->environment('local') && auth()->check())
    <div id="app-feedback" data-endpoint="{{ route('feedback.store') }}" data-request-id="{{ \Illuminate\Support\Str::uuid() }}">
        <button type="button" class="feedback-launcher" id="feedback-open" aria-haspopup="dialog">Feedback geben</button>
        <dialog id="feedback-dialog" class="feedback-dialog" aria-labelledby="feedback-heading">
            <form id="feedback-form">
                @csrf
                <header class="feedback-header">
                    <div><p class="feedback-eyebrow">PROTOTYP VERBESSERN</p><h2 id="feedback-heading">Dein Feedback</h2></div>
                    <button type="button" id="feedback-close" class="feedback-icon" aria-label="Feedback schließen">✕</button>
                </header>
                <p class="feedback-intro">Beschreibe, was wir verbessern können. Du entscheidest, ob du einen Screenshot hinzufügst.</p>
                <fieldset id="feedback-fields">
                    <label for="feedback-title">Kurztitel</label>
                    <input id="feedback-title" name="title" maxlength="160" required placeholder="Was ist dir aufgefallen?">
                    <label for="feedback-description">Beschreibung</label>
                    <textarea id="feedback-description" name="description" rows="4" maxlength="10000" required placeholder="Was ist passiert und was hast du erwartet?"></textarea>
                    <label for="feedback-page">Bereich</label>
                    <select id="feedback-page" name="page">
                        @foreach(app(\App\Actions\SubmitFeedback::class)->pages() as $value => $label)
                            <option value="{{ $value }}" @selected($value === (request()->is('admin/documents*') ? 'documents' : (request()->is('admin/ai-runs*') ? 'ai-runs' : (request()->is('admin/users*') ? 'users' : (request()->is('admin') ? 'dashboard' : 'other')))))>{{ $label }}</option>
                        @endforeach
                    </select>
                    <section class="feedback-capture" aria-label="Optionaler Screenshot">
                        <button type="button" id="feedback-capture">Screenshot aufnehmen</button>
                        <p id="feedback-capture-hint" class="feedback-note">Wähle im Browser den gewünschten Tab oder das Fenster. Es wird genau ein Bild ohne Ton aufgenommen.</p>
                        <div id="feedback-preview" hidden>
                            <p class="feedback-note">Vorschau prüfen. Zum Schwärzen über vertrauliche Stellen ziehen.</p>
                            <canvas id="feedback-canvas" aria-label="Screenshot-Vorschau; mit der Maus oder dem Finger Bereiche schwärzen"></canvas>
                            <button type="button" id="feedback-remove">Screenshot entfernen</button>
                        </div>
                    </section>
                    <details class="feedback-details" open>
                        <summary>Das wird übertragen</summary>
                        <p>Titel, Beschreibung, Bereich und folgender Kontext gehen an das private GitHub-Repository <strong>{{ config('feedback.repository') ?: '(noch nicht eingerichtet)' }}</strong>:</p>
                        <dl>
                            <dt>Feedbackgeber</dt><dd>{{ auth()->user()->name }} · {{ auth()->user()->email }}</dd>
                            <dt>Umgebung</dt><dd>{{ app()->environment() }}</dd>
                            <dt>Build</dt><dd>{{ config('feedback.build') }}</dd>
                            <dt>Plan</dt><dd>{{ config('feedback.plan') }}</dd>
                        </dl>
                        <p>Ein Screenshot bleibt intern gespeichert. Im Issue steht ein Link, den nur angemeldete Admins öffnen können. Keine automatische Übertragung von Seiteninhalten, URLs, Browser-Logs oder Sitzungsdaten.</p>
                    </details>
                    <label class="feedback-consent"><input type="checkbox" name="consent" value="1" required> <span>Ich habe Text und Screenshot geprüft und möchte diese Inhalte absenden.</span></label>
                </fieldset>
                @if(!config('feedback.token') || !config('feedback.repository'))
                    <p class="feedback-note">Der GitHub-Versand ist noch nicht eingerichtet. Ein Administrator muss Repository und Zugang konfigurieren.</p>
                @endif
                <p id="feedback-status" role="status" aria-live="polite"></p>
                <a id="feedback-issue" hidden target="_blank" rel="noopener noreferrer">Issue auf GitHub öffnen ↗</a>
                <footer><button type="submit" id="feedback-submit" class="feedback-primary" @disabled(!config('feedback.token') || !config('feedback.repository'))>Feedback absenden</button></footer>
            </form>
        </dialog>
    </div>
    @vite('resources/js/app.js')
@endif
