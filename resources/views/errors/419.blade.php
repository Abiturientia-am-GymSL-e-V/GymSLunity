@extends('errors.layout')

@section('code', '419')
@section('title', 'Seite abgelaufen')
@section('message', \App\Support\FormOfAddress::choose('Deine Sitzung ist abgelaufen. Bitte öffne die Startseite und versuche es erneut.', 'Ihre Sitzung ist abgelaufen. Bitte öffnen Sie die Startseite und versuchen Sie es erneut.'))
