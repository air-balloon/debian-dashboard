
CONTAINER_USER="$(shell id -u):$(shell id -g)"

IMAGE="floryn90/hugo:0.120.4-ext-alpine"

CONTAINER_NAME="debian.dashboard.air-balloon.cloud"
PROJECT_NAME="debian.dashboard.air-balloon.cloud"

version:
	docker run --name ${CONTAINER_NAME} --rm -it ${IMAGE} --version

init:
	docker run --name ${CONTAINER_NAME} --rm -it --user ${CONTAINER_USER} -v ${PWD}:/app --workdir /app ${IMAGE} new site ${PROJECT_NAME}

build:
	docker run --name ${CONTAINER_NAME} --rm --user ${CONTAINER_USER} -v ${PWD}:/app --workdir /app/${PROJECT_NAME} ${IMAGE} build

serve:
	docker run --name ${CONTAINER_NAME} --rm --user ${CONTAINER_USER} -v ${PWD}:/app --workdir /app/${PROJECT_NAME} -p 8122:8122 ${IMAGE} serve --bind 0.0.0.0 --port 8122 --baseURL localhost

date:
	date +'%Y-%m-%dT%H:%M:%SZ' --utc
