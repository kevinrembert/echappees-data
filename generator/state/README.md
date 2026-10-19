# Registres de génération

Les fichiers JSON de ce dossier sont suivis par Git pour préserver l'idempotence,
les identifiants de sujets, les contrôles et les budgets entre exécutions Actions.
Aucun secret ni corps de réponse OpenAI n'y est enregistré.

`budget.json` est créé au premier passage réel. La réservation est poussée avant
les appels payants. Une exécution interrompue conserve sa réservation ; ne pas
supprimer le registre ni relancer ses appels sous le même identifiant.
Les fichiers `audit-*.json` conservent les références et empreintes de provenance,
les licences et la mention des contrôles automatiques. Ils ne constituent pas
une certification humaine d'exactitude.
