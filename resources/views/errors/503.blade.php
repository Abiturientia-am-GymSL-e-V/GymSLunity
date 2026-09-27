@extends('errors.layout')

@section('code', '503')
@section('title', 'Dienst nicht verfügbar')
@section('message', \App\Support\FormOfAddress::choose('GymSLunity ist vorübergehend nicht verfügbar. Bitte versuche es später erneut.', 'GymSLunity ist vorübergehend nicht verfügbar. Bitte versuchen Sie es später erneut.'))
