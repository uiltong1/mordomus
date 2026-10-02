{{--
    E-mail do aviso (T6.1.5). A casca é uma só de propósito: o texto já nasceu no
    NotificationMessageService e está gravado na linha da notificação, e duas
    versões do mesmo aviso é o jeito de a casa receber "R$ 62,48" no push e
    "R$ 62,5" no e-mail. O template da linha (`mordomus::<evento>`) identifica
    qual aviso é — não qual arquivo o renderiza.
--}}
<x-mail::message>
# {{ $subject }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($actionUrl !== null)
<x-mail::button :url="$actionUrl">
Abrir o Mordomus
</x-mail::button>
@endif

{{-- O rodapé diz de onde vem o aviso: um morador que nunca deu permissão e
     ainda recebe coisas precisa saber que a casa é a fonte. --}}
<x-mail::footer>
Você recebe este aviso porque mora nesta residência no Mordomus.
</x-mail::footer>
</x-mail::message>
